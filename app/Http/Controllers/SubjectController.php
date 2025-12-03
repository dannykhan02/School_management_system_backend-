<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\School;
use App\Models\Stream;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;

class SubjectController extends Controller
{
    private function getUser(Request $request)
    {
        $user = Auth::user();
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }
        return $user;
    }

    private function checkSchoolStreamsEnabled(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return false;

        $school = School::find($user->school_id);
        if (!$school) return false;

        return $school->has_streams;
    }

    public function index(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$user->school_id)
            return response()->json(['message' => 'User is not associated with any school.'], 400);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $query = Subject::with(['school'])
            ->where('school_id', $user->school_id);

        // Filter by curriculum type if provided
        if ($request->has('curriculum_type')) {
            $query->where('curriculum_type', $request->curriculum_type);
        }

        // Filter by level if provided
        if ($request->has('level')) {
            $query->where('level', $request->level);
        }

        // Filter by category if provided
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by pathway if provided (for Senior Secondary)
        if ($request->has('pathway')) {
            $query->where('pathway', $request->pathway);
        }

        // Filter by core/elective if provided
        if ($request->has('is_core')) {
            $query->where('is_core', $request->boolean('is_core'));
        }

        if ($hasStreams) {
            $query->with(['streams', 'streams.teachers']);
        } else {
            $query->with(['teachers']);
        }

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'data' => $query->get()
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->getUser($request);

        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);
        $school = School::find($user->school_id);

        $validationRules = [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:255',
            'school_id' => 'required|integer|exists:schools,id',
            'curriculum_type' => ['required', Rule::in(['CBC', '8-4-4'])],
            'grade_level' => 'required|string|max:255',
            'level' => ['required', Rule::in(['Pre-Primary', 'Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary'])],
            'category' => 'required|string|max:255',
            'is_core' => 'required|boolean',
        ];

        // Pathway is required for Senior Secondary
        if ($request->has('level') && $request->level === 'Senior Secondary') {
            $validationRules['pathway'] = ['required', Rule::in(['STEM', 'Arts', 'Social Sciences'])];
        }

        if ($hasStreams) {
            $validationRules['streams'] = 'nullable|array';
            $validationRules['streams.*.stream_id'] = 'required|integer|exists:streams,id';
            $validationRules['streams.*.teacher_id'] = 'nullable|integer|exists:teachers,id';
        } else {
            $validationRules['teachers'] = 'nullable|array';
            $validationRules['teachers.*.teacher_id'] = 'required|integer|exists:teachers,id';
        }

        $validated = $request->validate($validationRules);

        if ($validated['school_id'] != $user->school_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You can only create subjects for your own school.'
            ], 403);
        }

        // Check if the school offers this curriculum type
        if ($validated['curriculum_type'] === 'CBC') {
            if (!in_array($school->primary_curriculum, ['CBC', 'Both']) && 
                !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['CBC', 'Both'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer the CBC curriculum.'
                ], 422);
            }
        } elseif ($validated['curriculum_type'] === '8-4-4') {
            if (!in_array($school->primary_curriculum, ['8-4-4', 'Both']) && 
                !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['8-4-4', 'Both'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer the 8-4-4 curriculum.'
                ], 422);
            }
        }

        // Check if the school offers this level
        $levelField = 'has_' . strtolower(str_replace(' ', '_', $validated['level']));
        if (!isset($school->$levelField) || $school->$levelField !== true) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your school does not offer the ' . $validated['level'] . ' level.'
            ], 422);
        }

        // For Senior Secondary, check if the school offers the pathway
        if ($validated['level'] === 'Senior Secondary' && isset($validated['pathway'])) {
            if (!$school->offersPathway($validated['pathway'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer the ' . $validated['pathway'] . ' pathway.'
                ], 422);
            }
        }

        $subject = Subject::create([
            'name' => $validated['name'],
            'code' => $validated['code'],
            'school_id' => $validated['school_id'],
            'curriculum_type' => $validated['curriculum_type'],
            'grade_level' => $validated['grade_level'],
            'level' => $validated['level'],
            'pathway' => $validated['pathway'] ?? null,
            'category' => $validated['category'],
            'is_core' => $validated['is_core'],
        ]);

        if ($hasStreams && isset($validated['streams'])) {
            foreach ($validated['streams'] as $streamData) {
                $stream = Stream::find($streamData['stream_id']);
                if (!$stream || $stream->school_id !== $user->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'One or more streams do not belong to your school.'
                    ], 422);
                }

                if (!empty($streamData['teacher_id'])) {
                    $teacher = Teacher::find($streamData['teacher_id']);
                    if (!$teacher || $teacher->school_id !== $user->school_id) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'One or more teachers do not belong to your school.'
                        ], 422);
                    }
                }

                $subject->streams()->attach($stream->id, [
                    'teacher_id' => $streamData['teacher_id'] ?? null
                ]);
            }
        }

        if (!$hasStreams && isset($validated['teachers'])) {
            foreach ($validated['teachers'] as $teacherData) {
                $teacher = Teacher::find($teacherData['teacher_id']);

                if (!$teacher || $teacher->school_id !== $user->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'One or more teachers do not belong to your school.'
                    ], 422);
                }

                $subject->teachers()->attach($teacher->id);
            }
        }

        $loadRelations = ['school'];
        $loadRelations[] = $hasStreams ? 'streams' : 'teachers';

        return response()->json([
            'status' => 'success',
            'message' => 'Subject created successfully.',
            'has_streams' => $hasStreams,
            'data' => $subject->load($loadRelations)
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $hasStreams = $this->checkSchoolStreamsEnabled($request);

            $loadRelations = ['school'];
            $loadRelations = $hasStreams
                ? array_merge($loadRelations, ['streams', 'streams.teachers'])
                : array_merge($loadRelations, ['teachers']);

            $subject = Subject::with($loadRelations)->findOrFail($id);

            if ($subject->school_id !== $user->school_id)
                return response()->json(['message' => 'Unauthorized access to this subject.'], 403);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 404);
        }

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'data' => $subject
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $subject = Subject::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 404);
        }

        if ($subject->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This subject does not belong to your school.'], 403);

        $school = School::find($user->school_id);

        $validationRules = [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255',
            'school_id' => 'sometimes|required|integer|exists:schools,id',
            'curriculum_type' => ['sometimes', 'required', Rule::in(['CBC', '8-4-4'])],
            'grade_level' => 'sometimes|required|string|max:255',
            'level' => ['sometimes', 'required', Rule::in(['Pre-Primary', 'Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary'])],
            'category' => 'sometimes|required|string|max:255',
            'is_core' => 'sometimes|required|boolean',
        ];

        // Pathway is required for Senior Secondary
        if ($request->has('level') && $request->level === 'Senior Secondary') {
            $validationRules['pathway'] = ['required', Rule::in(['STEM', 'Arts', 'Social Sciences'])];
        }

        $validated = $request->validate($validationRules);

        if (isset($validated['school_id']) && $validated['school_id'] != $user->school_id)
            return response()->json(['status' => 'error', 'message' => 'You cannot change the school of a subject.'], 403);

        // Check if the school offers this curriculum type
        if (isset($validated['curriculum_type'])) {
            if ($validated['curriculum_type'] === 'CBC') {
                if (!in_array($school->primary_curriculum, ['CBC', 'Both']) && 
                    !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['CBC', 'Both'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your school does not offer the CBC curriculum.'
                    ], 422);
                }
            } elseif ($validated['curriculum_type'] === '8-4-4') {
                if (!in_array($school->primary_curriculum, ['8-4-4', 'Both']) && 
                    !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['8-4-4', 'Both'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your school does not offer the 8-4-4 curriculum.'
                    ], 422);
                }
            }
        }

        // Check if the school offers this level
        if (isset($validated['level'])) {
            $levelField = 'has_' . strtolower(str_replace(' ', '_', $validated['level']));
            if (!isset($school->$levelField) || $school->$levelField !== true) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer the ' . $validated['level'] . ' level.'
                ], 422);
            }
        }

        // For Senior Secondary, check if the school offers the pathway
        if (isset($validated['level']) && $validated['level'] === 'Senior Secondary' && isset($validated['pathway'])) {
            if (!$school->offersPathway($validated['pathway'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer the ' . $validated['pathway'] . ' pathway.'
                ], 422);
            }
        }

        $subject->update($validated);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        return response()->json([
            'status' => 'success',
            'message' => 'Subject updated successfully.',
            'has_streams' => $hasStreams,
            'data' => $subject->load(['school', $hasStreams ? 'streams' : 'teachers'])
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $subject = Subject::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 404);
        }

        if ($subject->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This subject does not belong to your school.'], 403);

        $subject->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Subject deleted successfully.'
        ]);
    }

    public function getStreams(Request $request, $subjectId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $subject = Subject::findOrFail($subjectId);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 404);
        }

        if ($subject->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        $streams = $subject->streams()
            ->with(['school', 'teachers'])
            ->get();

        return response()->json([
            'status' => 'success',
            'subject' => $subject,
            'streams' => $streams
        ]);
    }

    public function assignToStreams(Request $request, $subjectId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$this->checkSchoolStreamsEnabled($request))
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);

        try {
            $subject = Subject::findOrFail($subjectId);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 404);
        }

        if ($subject->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a subject from another school.'], 403);

        $validated = $request->validate([
            'streams' => 'required|array',
            'streams.*.stream_id' => 'required|integer|exists:streams,id',
            'streams.*.teacher_id' => 'nullable|integer|exists:teachers,id',
        ]);

        $streamAssignments = [];

        foreach ($validated['streams'] as $streamData) {
            $stream = Stream::find($streamData['stream_id']);
            if (!$stream || $stream->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'One or more streams do not belong to your school.'
                ], 422);
            }

            if (!empty($streamData['teacher_id'])) {
                $teacher = Teacher::find($streamData['teacher_id']);
                if (!$teacher || $teacher->school_id !== $user->school_id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'One or more teachers do not belong to your school.'
                    ], 422);
                }
            }

            $streamAssignments[$stream->id] = [
                'teacher_id' => $streamData['teacher_id'] ?? null
            ];
        }

        $subject->streams()->sync($streamAssignments);

        return response()->json([
            'status' => 'success',
            'message' => 'Subject assigned to streams successfully.',
            'data' => $subject->load(['streams'])
        ]);
    }

    public function getTeachers(Request $request, $subjectId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request))
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers are assigned to subjects through streams, not directly.'
            ], 403);

        try {
            $subject = Subject::findOrFail($subjectId);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 404);
        }

        if ($subject->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized.'], 403);

        $teachers = $subject->teachers()->with('user')->get();

        return response()->json([
            'status' => 'success',
            'subject' => $subject,
            'teachers' => $teachers
        ]);
    }

    public function assignTeachers(Request $request, $subjectId)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if ($this->checkSchoolStreamsEnabled($request)) {
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers should be assigned to subjects through streams, not directly.'
            ], 403);
        }

        try {
            $subject = Subject::findOrFail($subjectId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No subject found with the specified ID.'
            ], 404);
        }

        if ($subject->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. You cannot modify a subject from another school.'], 403);

        $validated = $request->validate([
            'teachers' => 'required|array',
            'teachers.*.teacher_id' => 'required|integer|exists:teachers,id',
        ]);

        $teacherIds = [];

        foreach ($validated['teachers'] as $teacherData) {
            $teacher = Teacher::find($teacherData['teacher_id']);
            if (!$teacher || $teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'One or more teachers do not belong to your school.'
                ], 422);
            }

            $teacherIds[] = $teacher->id;
        }

        $subject->teachers()->sync($teacherIds);

        return response()->json([
            'status' => 'success',
            'message' => 'Teachers assigned successfully.',
            'data' => $subject->load(['teachers'])
        ]);
    }

    /**
     * Get subjects by curriculum type and level.
     */
    public function getByCurriculumAndLevel(Request $request, $curriculum, $level)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$user->school_id)
            return response()->json(['message' => 'User is not associated with any school.'], 400);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $query = Subject::with(['school'])
            ->where('school_id', $user->school_id)
            ->where('curriculum_type', $curriculum)
            ->where('level', $level);

        // Filter by pathway if provided (for Senior Secondary)
        if ($request->has('pathway')) {
            $query->where('pathway', $request->pathway);
        }

        // Filter by category if provided
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by core/elective if provided
        if ($request->has('is_core')) {
            $query->where('is_core', $request->boolean('is_core'));
        }

        if ($hasStreams) {
            $query->with(['streams', 'streams.teachers']);
        } else {
            $query->with(['teachers']);
        }

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'data' => $query->get()
        ]);
    }
}