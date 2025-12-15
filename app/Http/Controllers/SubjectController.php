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
    /**
     * Get curriculum types, grade levels, pathways, and categories constants.
     * This allows the frontend to fetch these values dynamically instead of hardcoding them.
     */
    public function getConstants()
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'curriculum_types' => ['CBC', '8-4-4'],
                'educational_levels' => [
                    'Pre-Primary',
                    'Primary',
                    'Junior Secondary',
                    'Secondary',
                    'Senior Secondary'
                ],
                'cbc_grade_levels' => [
                    'PP1-PP2 (Pre-Primary)',
                    'Grade 1-3 (Lower Primary)',
                    'Grade 4-6 (Upper Primary)',
                    'Grade 7-9 (Junior Secondary)',
                    'Grade 10-12 (Senior Secondary - STEM Pathway)',
                    'Grade 10-12 (Senior Secondary - Arts & Sports Science)',
                    'Grade 10-12 (Senior Secondary - Social Sciences)'
                ],
                'legacy_grade_levels' => [
                    'Standard 1-4',
                    'Standard 5-8',
                    'Form 1-4 (Secondary)'
                ],
                'senior_secondary_pathways' => ['STEM', 'Arts', 'Social Sciences'],
                'categories' => [
                    'Languages',
                    'Mathematics',
                    'Sciences',
                    'Humanities',
                    'Technical',
                    'Creative Arts',
                    'Physical Ed'
                ]
            ]
        ]);
    }

    /**
     * Search for subjects by name and return their details.
     * This is used for autofilling subject code and category when user types a subject name.
     */
    public function searchSubjectByName(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$user->school_id)
            return response()->json(['message' => 'User is not associated with any school.'], 400);

        $school = School::find($user->school_id);
        if (!$school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        $schoolLevels = $this->getSchoolLevels($school);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'curriculum_type' => 'nullable|string|in:CBC,8-4-4',
            'level' => 'nullable|string|in:Pre-Primary,Primary,Junior Secondary,Senior Secondary,Secondary',
            'pathway' => 'nullable|string|in:STEM,Arts,Social Sciences',
        ]);

        $query = Subject::where('name', 'like', '%' . $validated['name'] . '%');

        // Filter by curriculum type if provided
        if (isset($validated['curriculum_type'])) {
            // Check if school offers this curriculum
            if ($validated['curriculum_type'] === 'CBC') {
                if (!in_array($school->primary_curriculum, ['CBC', 'Both']) && 
                    !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['CBC', 'Both'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your school does not offer CBC curriculum.'
                    ], 422);
                }
            } elseif ($validated['curriculum_type'] === '8-4-4') {
                if (!in_array($school->primary_curriculum, ['8-4-4', 'Both']) && 
                    !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['8-4-4', 'Both'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your school does not offer 8-4-4 curriculum.'
                    ], 422);
                }
            }
            
            $query->where('curriculum_type', $validated['curriculum_type']);
        }

        // Filter by level if provided
        if (isset($validated['level'])) {
            // Check if school offers this level
            if (!in_array($validated['level'], $schoolLevels)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer ' . $validated['level'] . ' level.'
                ], 422);
            }
            
            $query->where('level', $validated['level']);
        }

        // Filter by pathway if provided (for Senior Secondary)
        if (isset($validated['pathway'])) {
            // Check if school offers this pathway
            if (!$school->offersPathway($validated['pathway'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer ' . $validated['pathway'] . ' pathway.'
                ], 422);
            }
            
            $query->where('pathway', $validated['pathway']);
        }

        // Get subjects that match the name
        $subjects = $query->get();

        // Group subjects by name and collect unique codes and categories
        $groupedSubjects = [];
        foreach ($subjects as $subject) {
            $name = $subject->name;
            if (!isset($groupedSubjects[$name])) {
                $groupedSubjects[$name] = [
                    'name' => $name,
                    'codes' => [],
                    'categories' => [],
                    'curriculum_types' => [],
                    'levels' => [],
                    'pathways' => [],
                ];
            }
            
            $groupedSubjects[$name]['codes'][] = $subject->code;
            $groupedSubjects[$name]['categories'][] = $subject->category;
            $groupedSubjects[$name]['curriculum_types'][] = $subject->curriculum_type;
            $groupedSubjects[$name]['levels'][] = $subject->level;
            
            if ($subject->pathway) {
                $groupedSubjects[$name]['pathways'][] = $subject->pathway;
            }
        }

        // Remove duplicates and convert to arrays
        foreach ($groupedSubjects as $name => &$data) {
            $data['codes'] = array_values(array_unique($data['codes']));
            $data['categories'] = array_values(array_unique($data['categories']));
            $data['curriculum_types'] = array_values(array_unique($data['curriculum_types']));
            $data['levels'] = array_values(array_unique($data['levels']));
            $data['pathways'] = array_values(array_unique($data['pathways']));
        }

        return response()->json([
            'status' => 'success',
            'data' => array_values($groupedSubjects)
        ]);
    }

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

    private function getSchoolLevels($school)
    {
        $levels = [];
        
        if ($school->has_pre_primary) $levels[] = 'Pre-Primary';
        if ($school->has_primary) $levels[] = 'Primary';
        if ($school->has_junior_secondary) $levels[] = 'Junior Secondary';
        if ($school->has_senior_secondary) $levels[] = 'Senior Secondary';
        if ($school->has_secondary) $levels[] = 'Secondary';
        
        return $levels;
    }

    public function index(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$user->school_id)
            return response()->json(['message' => 'User is not associated with any school.'], 400);

        $school = School::find($user->school_id);
        if (!$school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        $hasStreams = $this->checkSchoolStreamsEnabled($request);
        $schoolLevels = $this->getSchoolLevels($school);

        $query = Subject::with(['school'])
            ->where('school_id', $user->school_id)
            ->whereIn('level', $schoolLevels); // Only get subjects for levels the school offers

        // Filter by curriculum type if provided
        if ($request->has('curriculum_type')) {
            // Check if school offers this curriculum
            if ($request->curriculum_type === 'CBC') {
                if (!in_array($school->primary_curriculum, ['CBC', 'Both']) && 
                    !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['CBC', 'Both'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your school does not offer CBC curriculum.'
                    ], 422);
                }
            } elseif ($request->curriculum_type === '8-4-4') {
                if (!in_array($school->primary_curriculum, ['8-4-4', 'Both']) && 
                    !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['8-4-4', 'Both'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your school does not offer 8-4-4 curriculum.'
                    ], 422);
                }
            }
            
            $query->where('curriculum_type', $request->curriculum_type);
        }

        // Filter by level if provided
        if ($request->has('level')) {
            // Check if school offers this level
            if (!in_array($request->level, $schoolLevels)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer ' . $request->level . ' level.'
                ], 422);
            }
            
            $query->where('level', $request->level);
        }

        // Filter by category if provided
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by pathway if provided (for Senior Secondary)
        if ($request->has('pathway')) {
            // Check if school offers this pathway
            if (!$school->offersPathway($request->pathway)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer ' . $request->pathway . ' pathway.'
                ], 422);
            }
            
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
            'school_levels' => $schoolLevels,
            'data' => $query->get()
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->getUser($request);

        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $hasStreams = $this->checkSchoolStreamsEnabled($request);
        $school = School::find($user->school_id);
        
        if (!$school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        $schoolLevels = $this->getSchoolLevels($school);

        try {
            // Basic validation for the fields the frontend sends
            $validationRules = [
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:255',
                'school_id' => 'required|integer|exists:schools,id',
                'category' => 'required|string|max:255',
                'is_core' => 'required|boolean',
            ];

            $validated = $request->validate($validationRules);

            // Check if the subject belongs to the user's school
            if ($validated['school_id'] != $user->school_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You can only create subjects for your own school.'
                ], 403);
            }

            // Validate that the category is one of the allowed categories
            $allowedCategories = ['Languages', 'Mathematics', 'Sciences', 'Humanities', 'Technical', 'Creative Arts', 'Physical Ed'];
            if (!in_array($validated['category'], $allowedCategories)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid category. Please select from the available categories.'
                ], 422);
            }

            // Determine curriculum type based on school settings
            $curriculumType = $school->primary_curriculum;
            if ($school->secondary_curriculum && $school->secondary_curriculum !== $school->primary_curriculum) {
                $curriculumType = $school->primary_curriculum; // Default to primary curriculum
            }

            // Determine level based on school settings
            $level = $schoolLevels[0] ?? 'Secondary'; // Default to first available level

            // Determine grade levels based on school settings
            $gradeLevels = $school->grade_levels ?? [];

            // Determine pathway if Senior Secondary
            $pathway = null;
            if ($level === 'Senior Secondary' && !empty($school->senior_secondary_pathways)) {
                $pathway = $school->senior_secondary_pathways[0]; // Default to first pathway
            }

            // Create subject with auto-filled values from school settings
            $subject = Subject::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'school_id' => $validated['school_id'],
                'curriculum_type' => $curriculumType,
                'grade_level' => implode(', ', $gradeLevels),
                'level' => $level,
                'pathway' => $pathway,
                'category' => $validated['category'],
                'is_core' => $validated['is_core'],
            ]);

            if ($hasStreams && isset($request->streams)) {
                foreach ($request->streams as $streamData) {
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

            if (!$hasStreams && isset($request->teachers)) {
                foreach ($request->teachers as $teacherData) {
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
                'school_levels' => $schoolLevels,
                'data' => $subject->load($loadRelations)
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create subject: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try {
            $hasStreams = $this->checkSchoolStreamsEnabled($request);
            $school = School::find($user->school_id);
            $schoolLevels = $this->getSchoolLevels($school);

            $loadRelations = ['school'];
            $loadRelations = $hasStreams
                ? array_merge($loadRelations, ['streams', 'streams.teachers'])
                : array_merge($loadRelations, ['teachers']);

            $subject = Subject::with($loadRelations)->findOrFail($id);

            if ($subject->school_id !== $user->school_id)
                return response()->json(['message' => 'Unauthorized access to this subject.'], 403);

        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
        }

        return response()->json([
            'status' => 'success',
            'has_streams' => $hasStreams,
            'school_levels' => $schoolLevels,
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
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
        }

        if ($subject->school_id !== $user->school_id)
            return response()->json(['message' => 'Unauthorized. This subject does not belong to your school.'], 403);

        $school = School::find($user->school_id);
        $schoolLevels = $this->getSchoolLevels($school);

        $validationRules = [
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:255',
            'school_id' => 'sometimes|required|integer|exists:schools,id',
            'category' => 'sometimes|required|string|max:255',
            'is_core' => 'sometimes|required|boolean',
        ];

        $validated = $request->validate($validationRules);

        if (isset($validated['school_id']) && $validated['school_id'] != $user->school_id)
            return response()->json(['status' => 'error', 'message' => 'You cannot change the school of a subject.'], 403);

        // Update only the fields that were actually provided
        $updateData = [];
        if (isset($validated['name'])) $updateData['name'] = $validated['name'];
        if (isset($validated['code'])) $updateData['code'] = $validated['code'];
        if (isset($validated['category'])) $updateData['category'] = $validated['category'];
        if (isset($validated['is_core'])) $updateData['is_core'] = $validated['is_core'];

        $subject->update($updateData);

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        return response()->json([
            'status' => 'success',
            'message' => 'Subject updated successfully.',
            'has_streams' => $hasStreams,
            'school_levels' => $schoolLevels,
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
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
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
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
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
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
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
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
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
            ], 422);
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

        $school = School::find($user->school_id);
        if (!$school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        $schoolLevels = $this->getSchoolLevels($school);

        // Check if school offers this curriculum
        if ($curriculum === 'CBC') {
            if (!in_array($school->primary_curriculum, ['CBC', 'Both']) && 
                !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['CBC', 'Both'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer CBC curriculum.'
                ], 422);
            }
        } elseif ($curriculum === '8-4-4') {
            if (!in_array($school->primary_curriculum, ['8-4-4', 'Both']) && 
                !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['8-4-4', 'Both'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer 8-4-4 curriculum.'
                ], 422);
            }
        }

        // Check if school offers this level
        if (!in_array($level, $schoolLevels)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your school does not offer ' . $level . ' level.'
            ], 422);
        }

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $query = Subject::with(['school'])
            ->where('school_id', $user->school_id)
            ->where('curriculum_type', $curriculum)
            ->where('level', $level);

        // Filter by pathway if provided (for Senior Secondary)
        if ($request->has('pathway')) {
            // Check if school offers this pathway
            if (!$school->offersPathway($request->pathway)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer ' . $request->pathway . ' pathway.'
                ], 422);
            }
            
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
            'school_levels' => $schoolLevels,
            'data' => $query->get()
        ]);
    }

    /**
     * Get subjects by school level with optional filters.
     */
    public function getBySchoolLevel(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        if (!$user->school_id)
            return response()->json(['message' => 'User is not associated with any school.'], 400);

        $school = School::find($user->school_id);
        if (!$school) {
            return response()->json(['message' => 'School not found.'], 404);
        }

        $schoolLevels = $this->getSchoolLevels($school);

        // Get the level from request
        $level = $request->input('level');
        if (!$level) {
            return response()->json([
                'status' => 'error',
                'message' => 'Level parameter is required.'
            ], 400);
        }

        // Check if school offers this level
        if (!in_array($level, $schoolLevels)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Your school does not offer ' . $level . ' level.'
            ], 422);
        }

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $query = Subject::with(['school'])
            ->where('school_id', $user->school_id)
            ->where('level', $level);

        // Filter by curriculum type if provided
        if ($request->has('curriculum_type')) {
            // Check if school offers this curriculum
            if ($request->curriculum_type === 'CBC') {
                if (!in_array($school->primary_curriculum, ['CBC', 'Both']) && 
                    !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['CBC', 'Both'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your school does not offer CBC curriculum.'
                    ], 422);
                }
            } elseif ($request->curriculum_type === '8-4-4') {
                if (!in_array($school->primary_curriculum, ['8-4-4', 'Both']) && 
                    !in_array($school->secondary_curriculum ?? $school->primary_curriculum, ['8-4-4', 'Both'])) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Your school does not offer 8-4-4 curriculum.'
                    ], 422);
                }
            }
            
            $query->where('curriculum_type', $request->curriculum_type);
        }

        // Filter by pathway if provided (for Senior Secondary)
        if ($request->has('pathway')) {
            // Check if school offers this pathway
            if (!$school->offersPathway($request->pathway)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Your school does not offer ' . $request->pathway . ' pathway.'
                ], 422);
            }
            
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
            'school_levels' => $schoolLevels,
            'data' => $query->get()
        ]);
    }
}