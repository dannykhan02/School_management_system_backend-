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

        $subjects = Subject::with(['school'])
            ->where('school_id', $user->school_id);

        if ($hasStreams) {
            $subjects->with(['streams', 'streams.teachers']);
        } else {
            $subjects->with(['teachers']);
        }

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'data' => $subjects->get()
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->getUser($request);

        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $validationRules = [
            'subject_name' => 'required|string|max:255',
            'subject_code' => 'required|string|max:255',
            'school_id' => 'required|integer|exists:schools,id',
        ];

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

        $subject = Subject::create([
            'subject_name' => $validated['subject_name'],
            'subject_code' => $validated['subject_code'],
            'school_id' => $validated['school_id'],
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

        $validated = $request->validate([
            'subject_name' => 'sometimes|required|string|max:255',
            'subject_code' => 'sometimes|required|string|max:255',
            'school_id' => 'sometimes|required|integer|exists:schools,id'
        ]);

        if (isset($validated['school_id']) && $validated['school_id'] != $user->school_id)
            return response()->json(['status' => 'error', 'message' => 'You cannot change the school of a subject.'], 403);

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
}