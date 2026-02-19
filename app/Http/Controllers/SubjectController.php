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
    // ─────────────────────────────────────────────────────────────────────────
    // CONSTANTS / LOOKUP HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Canonical map of  level  =>  allowed grade_level values.
     * Used for validation across the controller.
     */
    private const LEVEL_GRADE_MAP = [
        'Pre-Primary'      => ['PP1-PP2'],
        'Primary'          => ['Grade 1-3', 'Grade 4-6', 'Standard 1-4', 'Standard 5-8'],
        'Junior Secondary' => ['Grade 7-9'],
        'Senior Secondary' => ['Grade 10-12'],
        'Secondary'        => ['Form 1-4'],
    ];

    /**
     * Pathways only exist at Senior Secondary level.
     */
    private const SENIOR_SECONDARY_PATHWAYS = ['STEM', 'Arts', 'Social Sciences'];

    private const ALLOWED_CATEGORIES = [
        'Languages', 'Mathematics', 'Sciences', 'Humanities',
        'Technical', 'Creative Arts', 'Physical Ed',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // CONSTANTS ENDPOINT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/subjects/constants
     * Returns curriculum/level/grade/pathway/category enums for frontend dropdowns.
     */
    public function getConstants()
    {
        return response()->json([
            'status' => 'success',
            'data'   => [
                'curriculum_types'          => ['CBC', '8-4-4'],
                'educational_levels'        => array_keys(self::LEVEL_GRADE_MAP),
                'level_grade_map'           => self::LEVEL_GRADE_MAP,
                'senior_secondary_pathways' => self::SENIOR_SECONDARY_PATHWAYS,
                'categories'                => self::ALLOWED_CATEGORIES,

                // Convenience groupings for CBC
                'cbc_grade_levels' => [
                    'PP1-PP2',
                    'Grade 1-3',
                    'Grade 4-6',
                    'Grade 7-9',
                    'Grade 10-12',
                ],
                // Convenience groupings for 8-4-4
                'legacy_grade_levels' => [
                    'Standard 1-4',
                    'Standard 5-8',
                    'Form 1-4',
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SPECIAL FILTERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/subjects/by-learning-area
     * Returns CBC subjects grouped by learning_area for a school.
     */
    public function getByLearningArea(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        $subjects = Subject::where('school_id', $user->school_id)
            ->where('curriculum_type', 'CBC')
            ->get()
            ->groupBy('learning_area');

        $result = $subjects->map(fn($areaSubjects, $learningArea) => [
            'learning_area' => $learningArea,
            'subjects'      => $areaSubjects->map(fn($s) => [
                'id'                     => $s->id,
                'name'                   => $s->name,
                'code'                   => $s->code,
                'grade_level'            => $s->grade_level,
                'pathway'                => $s->pathway,
                'is_kicd_compulsory'     => $s->is_kicd_compulsory,
                'minimum_weekly_periods' => $s->minimum_weekly_periods,
                'maximum_weekly_periods' => $s->maximum_weekly_periods,
            ])->values(),
        ])->values();

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    /**
     * GET /api/subjects/kicd-required/{level}
     * Returns all KICD-compulsory subjects for a given level.
     */
    public function getKICDRequired(Request $request, $level)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        if (!array_key_exists($level, self::LEVEL_GRADE_MAP)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid level.'], 422);
        }

        $subjects = Subject::where('school_id', $user->school_id)
            ->where('level', $level)
            ->where('is_kicd_compulsory', true)
            ->get();

        return response()->json(['status' => 'success', 'level' => $level, 'data' => $subjects]);
    }

    /**
     * GET /api/subjects/by-grade
     * Returns subjects filtered by grade_level (and optionally pathway/curriculum).
     *
     * Query params:
     *   grade_level  (required)  e.g. "Grade 7-9", "Form 1-4"
     *   pathway      (optional)  e.g. "STEM"  — only meaningful for "Grade 10-12"
     *   curriculum_type (optional)
     *   is_core      (optional)
     */
    public function getByGradeLevel(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        $gradeLevel = $request->input('grade_level');
        if (!$gradeLevel) {
            return response()->json(['status' => 'error', 'message' => 'grade_level parameter is required.'], 400);
        }

        // Validate grade_level against the canonical map
        $allGradeLevels = collect(self::LEVEL_GRADE_MAP)->flatten()->all();
        if (!in_array($gradeLevel, $allGradeLevels)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid grade_level value.'], 422);
        }

        $school = School::find($user->school_id);
        if (!$school) return response()->json(['message' => 'School not found.'], 404);

        // Derive the level from the grade_level
        $level = $this->levelFromGrade($gradeLevel);

        // Check school offers this level
        $schoolLevels = $this->getSchoolLevels($school);
        if (!in_array($level, $schoolLevels)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Your school does not offer the {$level} level.",
            ], 422);
        }

        $query = Subject::where('school_id', $user->school_id)
            ->where('grade_level', $gradeLevel);

        // Pathway filter — only valid for Senior Secondary (Grade 10-12)
        if ($request->filled('pathway')) {
            if ($level !== 'Senior Secondary') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Pathway filter is only applicable to Senior Secondary (Grade 10-12).',
                ], 422);
            }
            $pathway = $request->input('pathway');
            if (!in_array($pathway, self::SENIOR_SECONDARY_PATHWAYS)) {
                return response()->json(['status' => 'error', 'message' => 'Invalid pathway.'], 422);
            }
            if (!$school->offersPathway($pathway)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Your school does not offer the {$pathway} pathway.",
                ], 422);
            }
            $query->where('pathway', $pathway);
        }

        // Optional filters
        if ($request->filled('curriculum_type')) {
            $curriculum = $request->input('curriculum_type');
            $err = $this->validateCurriculumForSchool($curriculum, $school);
            if ($err) return $err;
            $query->where('curriculum_type', $curriculum);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->has('is_core')) {
            $query->where('is_core', $request->boolean('is_core'));
        }

        $hasStreams = $this->checkSchoolStreamsEnabled($request);
        $this->applyRelations($query, $hasStreams);

        return response()->json([
            'status'        => 'success',
            'grade_level'   => $gradeLevel,
            'level'         => $level,
            'has_streams'   => $hasStreams,
            'school_levels' => $schoolLevels,
            'data'          => $query->get(),
        ]);
    }

    /**
     * GET /api/subjects/by-pathway/{pathway}
     * Returns Senior Secondary subjects for a given pathway.
     */
    public function getByPathway(Request $request, $pathway)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        if (!in_array($pathway, self::SENIOR_SECONDARY_PATHWAYS)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid pathway.'], 422);
        }

        $school = School::find($user->school_id);
        if (!$school) return response()->json(['message' => 'School not found.'], 404);

        if (!$school->offersPathway($pathway)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Your school does not offer the {$pathway} pathway.",
            ], 422);
        }

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $query = Subject::where('school_id', $user->school_id)
            ->where('level', 'Senior Secondary')
            ->where('pathway', $pathway);

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->has('is_core')) {
            $query->where('is_core', $request->boolean('is_core'));
        }

        $this->applyRelations($query, $hasStreams);

        return response()->json([
            'status'      => 'success',
            'pathway'     => $pathway,
            'has_streams' => $hasStreams,
            'data'        => $query->get(),
        ]);
    }

    /**
     * GET /api/subjects/search
     * Searches subjects by name, returning grouped suggestions for autofill.
     */
    public function searchSubjectByName(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        $school = School::find($user->school_id);
        if (!$school) return response()->json(['message' => 'School not found.'], 404);

        $validated = $request->validate([
            'name'            => 'required|string|max:255',
            'curriculum_type' => 'nullable|string|in:CBC,8-4-4',
            'grade_level'     => 'nullable|string',
            'pathway'         => 'nullable|string|in:STEM,Arts,Social Sciences',
        ]);

        $query = Subject::where('school_id', $user->school_id)
            ->where('name', 'like', '%' . $validated['name'] . '%');

        if (!empty($validated['curriculum_type'])) {
            $err = $this->validateCurriculumForSchool($validated['curriculum_type'], $school);
            if ($err) return $err;
            $query->where('curriculum_type', $validated['curriculum_type']);
        }

        if (!empty($validated['grade_level'])) {
            $query->where('grade_level', $validated['grade_level']);
        }

        if (!empty($validated['pathway'])) {
            if (!$school->offersPathway($validated['pathway'])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Your school does not offer the {$validated['pathway']} pathway.",
                ], 422);
            }
            $query->where('pathway', $validated['pathway']);
        }

        $subjects = $query->get();

        // Group by name and collect unique metadata
        $grouped = [];
        foreach ($subjects as $subject) {
            $name = $subject->name;
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'name'             => $name,
                    'codes'            => [],
                    'categories'       => [],
                    'curriculum_types' => [],
                    'levels'           => [],
                    'grade_levels'     => [],
                    'pathways'         => [],
                ];
            }
            $grouped[$name]['codes'][]            = $subject->code;
            $grouped[$name]['categories'][]       = $subject->category;
            $grouped[$name]['curriculum_types'][] = $subject->curriculum_type;
            $grouped[$name]['levels'][]           = $subject->level;
            $grouped[$name]['grade_levels'][]     = $subject->grade_level;
            if ($subject->pathway) {
                $grouped[$name]['pathways'][] = $subject->pathway;
            }
        }

        foreach ($grouped as &$data) {
            foreach ($data as $key => $val) {
                if (is_array($val)) {
                    $data[$key] = array_values(array_unique($val));
                }
            }
        }

        return response()->json(['status' => 'success', 'data' => array_values($grouped)]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CRUD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/subjects
     * Returns subjects for the authenticated user's school, with optional filters.
     *
     * Query params:
     *   curriculum_type, level, grade_level, pathway, category, is_core
     */
    public function index(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();
        if (!$user->school_id) return $this->noSchool();

        $school = School::find($user->school_id);
        if (!$school) return response()->json(['message' => 'School not found.'], 404);

        $schoolLevels = $this->getSchoolLevels($school);
        $hasStreams   = $this->checkSchoolStreamsEnabled($request);

        $query = Subject::with(['school'])
            ->where('school_id', $user->school_id)
            ->whereIn('level', $schoolLevels);

        // ── curriculum_type filter ────────────────────────────────────────
        if ($request->filled('curriculum_type')) {
            $err = $this->validateCurriculumForSchool($request->curriculum_type, $school);
            if ($err) return $err;
            $query->where('curriculum_type', $request->curriculum_type);
        }

        // ── level filter ──────────────────────────────────────────────────
        if ($request->filled('level')) {
            if (!in_array($request->level, $schoolLevels)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Your school does not offer the {$request->level} level.",
                ], 422);
            }
            $query->where('level', $request->level);
        }

        // ── grade_level filter ────────────────────────────────────────────
        if ($request->filled('grade_level')) {
            $query->where('grade_level', $request->grade_level);
        }

        // ── pathway filter ────────────────────────────────────────────────
        if ($request->filled('pathway')) {
            if (!in_array($request->pathway, self::SENIOR_SECONDARY_PATHWAYS)) {
                return response()->json(['status' => 'error', 'message' => 'Invalid pathway.'], 422);
            }
            if (!$school->offersPathway($request->pathway)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Your school does not offer the {$request->pathway} pathway.",
                ], 422);
            }
            $query->where('pathway', $request->pathway);
        }

        // ── category / is_core filters ────────────────────────────────────
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('is_core')) {
            $query->where('is_core', $request->boolean('is_core'));
        }

        $this->applyRelations($query, $hasStreams);

        return response()->json([
            'status'        => 'success',
            'has_streams'   => $hasStreams,
            'school_levels' => $schoolLevels,
            'data'          => $query->get(),
        ]);
    }

    /**
     * POST /api/subjects
     * Creates a new subject for the authenticated user's school.
     *
     * Required body: name, code, school_id, category, is_core,
     *                grade_level (new — must match level_grade_map)
     * Optional body: pathway (required when grade_level === 'Grade 10-12')
     */
    public function store(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        $school = School::find($user->school_id);
        if (!$school) return response()->json(['message' => 'School not found.'], 404);

        $schoolLevels  = $this->getSchoolLevels($school);
        $allGradeLevels = collect(self::LEVEL_GRADE_MAP)->flatten()->all();

        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:255',
                'code'        => 'required|string|max:255',
                'school_id'   => 'required|integer|exists:schools,id',
                'category'    => 'required|string|in:' . implode(',', self::ALLOWED_CATEGORIES),
                'is_core'     => 'required|boolean',
                'grade_level' => 'required|string|in:' . implode(',', $allGradeLevels),
                'pathway'     => 'nullable|string|in:' . implode(',', self::SENIOR_SECONDARY_PATHWAYS),
            ]);

            if ($validated['school_id'] != $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'You can only create subjects for your own school.',
                ], 403);
            }

            // Derive level from grade_level
            $level = $this->levelFromGrade($validated['grade_level']);

            if (!in_array($level, $schoolLevels)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Your school does not offer the {$level} level.",
                ], 422);
            }

            // Pathway is required for Senior Secondary
            $pathway = null;
            if ($level === 'Senior Secondary') {
                if (empty($validated['pathway'])) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'A pathway is required for Senior Secondary subjects.',
                    ], 422);
                }
                if (!$school->offersPathway($validated['pathway'])) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Your school does not offer the {$validated['pathway']} pathway.",
                    ], 422);
                }
                $pathway = $validated['pathway'];
            }

            // Determine curriculum_type from the school
            $curriculumType = $school->primary_curriculum;

            $subject = Subject::create([
                'name'            => $validated['name'],
                'code'            => $validated['code'],
                'school_id'       => $validated['school_id'],
                'curriculum_type' => $curriculumType,
                'grade_level'     => $validated['grade_level'],
                'level'           => $level,
                'pathway'         => $pathway,
                'category'        => $validated['category'],
                'is_core'         => $validated['is_core'],
            ]);

            $hasStreams = $this->checkSchoolStreamsEnabled($request);

            // Attach streams or teachers
            if ($hasStreams && $request->has('streams')) {
                foreach ($request->streams as $streamData) {
                    $stream = Stream::find($streamData['stream_id']);
                    if (!$stream || $stream->school_id !== $user->school_id) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'One or more streams do not belong to your school.',
                        ], 422);
                    }
                    $subject->streams()->attach($stream->id, [
                        'teacher_id' => $streamData['teacher_id'] ?? null,
                    ]);
                }
            }

            if (!$hasStreams && $request->has('teachers')) {
                foreach ($request->teachers as $teacherData) {
                    $teacher = Teacher::find($teacherData['teacher_id']);
                    if (!$teacher || $teacher->school_id !== $user->school_id) {
                        return response()->json([
                            'status'  => 'error',
                            'message' => 'One or more teachers do not belong to your school.',
                        ], 422);
                    }
                    $subject->teachers()->attach($teacher->id);
                }
            }

            $loadRelations = ['school', $hasStreams ? 'streams' : 'teachers'];

            return response()->json([
                'status'        => 'success',
                'message'       => 'Subject created successfully.',
                'has_streams'   => $hasStreams,
                'school_levels' => $schoolLevels,
                'data'          => $subject->load($loadRelations),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create subject: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/subjects/{id}
     */
    public function show(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        try {
            $hasStreams    = $this->checkSchoolStreamsEnabled($request);
            $school        = School::find($user->school_id);
            $schoolLevels  = $this->getSchoolLevels($school);

            $loadRelations = $hasStreams
                ? ['school', 'streams', 'streams.teachers']
                : ['school', 'teachers'];

            $subject = Subject::with($loadRelations)->findOrFail($id);

            if ($subject->school_id !== $user->school_id) {
                return response()->json(['message' => 'Unauthorized access to this subject.'], 403);
            }
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
        }

        return response()->json([
            'status'        => 'success',
            'has_streams'   => $hasStreams,
            'school_levels' => $schoolLevels,
            'data'          => $subject,
        ]);
    }

    /**
     * PUT/PATCH /api/subjects/{id}
     */
    public function update(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        try {
            $subject = Subject::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
        }

        if ($subject->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This subject does not belong to your school.'], 403);
        }

        $school       = School::find($user->school_id);
        $schoolLevels = $this->getSchoolLevels($school);
        $allGradeLevels = collect(self::LEVEL_GRADE_MAP)->flatten()->all();

        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'code'        => 'sometimes|required|string|max:255',
            'school_id'   => 'sometimes|required|integer|exists:schools,id',
            'category'    => 'sometimes|required|string|in:' . implode(',', self::ALLOWED_CATEGORIES),
            'is_core'     => 'sometimes|required|boolean',
            'grade_level' => 'sometimes|required|string|in:' . implode(',', $allGradeLevels),
            'pathway'     => 'nullable|string|in:' . implode(',', self::SENIOR_SECONDARY_PATHWAYS),
        ]);

        if (isset($validated['school_id']) && $validated['school_id'] != $user->school_id) {
            return response()->json(['status' => 'error', 'message' => 'You cannot change the school of a subject.'], 403);
        }

        $updateData = [];

        if (isset($validated['name']))     $updateData['name']     = $validated['name'];
        if (isset($validated['code']))     $updateData['code']     = $validated['code'];
        if (isset($validated['category'])) $updateData['category'] = $validated['category'];
        if (isset($validated['is_core']))  $updateData['is_core']  = $validated['is_core'];

        // If grade_level is being updated, re-derive level and validate pathway
        if (isset($validated['grade_level'])) {
            $newLevel = $this->levelFromGrade($validated['grade_level']);
            if (!in_array($newLevel, $schoolLevels)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Your school does not offer the {$newLevel} level.",
                ], 422);
            }

            $updateData['grade_level'] = $validated['grade_level'];
            $updateData['level']       = $newLevel;

            if ($newLevel === 'Senior Secondary') {
                $pathway = $validated['pathway'] ?? $subject->pathway;
                if (!$pathway || !in_array($pathway, self::SENIOR_SECONDARY_PATHWAYS)) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'A valid pathway is required for Senior Secondary subjects.',
                    ], 422);
                }
                $updateData['pathway'] = $pathway;
            } else {
                $updateData['pathway'] = null; // Clear pathway if moved out of Senior Secondary
            }
        } elseif (array_key_exists('pathway', $validated)) {
            // Pathway-only update (only valid for Senior Secondary)
            if ($subject->level === 'Senior Secondary') {
                $updateData['pathway'] = $validated['pathway'];
            }
        }

        $subject->update($updateData);
        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        return response()->json([
            'status'        => 'success',
            'message'       => 'Subject updated successfully.',
            'has_streams'   => $hasStreams,
            'school_levels' => $schoolLevels,
            'data'          => $subject->load(['school', $hasStreams ? 'streams' : 'teachers']),
        ]);
    }

    /**
     * DELETE /api/subjects/{id}
     */
    public function destroy(Request $request, $id)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        try {
            $subject = Subject::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
        }

        if ($subject->school_id !== $user->school_id) {
            return response()->json(['message' => 'Unauthorized. This subject does not belong to your school.'], 403);
        }

        $subject->delete();

        return response()->json(['status' => 'success', 'message' => 'Subject deleted successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STREAMS & TEACHERS
    // ─────────────────────────────────────────────────────────────────────────

    public function getStreams(Request $request, $subjectId)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        if (!$this->checkSchoolStreamsEnabled($request)) {
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);
        }

        $subject = $this->findSubjectForSchool($subjectId, $user->school_id);
        if ($subject instanceof \Illuminate\Http\JsonResponse) return $subject;

        return response()->json([
            'status'  => 'success',
            'subject' => $subject,
            'streams' => $subject->streams()->with(['school', 'teachers'])->get(),
        ]);
    }

    public function assignToStreams(Request $request, $subjectId)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        if (!$this->checkSchoolStreamsEnabled($request)) {
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);
        }

        $subject = $this->findSubjectForSchool($subjectId, $user->school_id);
        if ($subject instanceof \Illuminate\Http\JsonResponse) return $subject;

        $validated = $request->validate([
            'streams'               => 'required|array',
            'streams.*.stream_id'   => 'required|integer|exists:streams,id',
            'streams.*.teacher_id'  => 'nullable|integer|exists:teachers,id',
        ]);

        $assignments = [];
        foreach ($validated['streams'] as $streamData) {
            $stream = Stream::find($streamData['stream_id']);
            if (!$stream || $stream->school_id !== $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'One or more streams do not belong to your school.',
                ], 422);
            }
            if (!empty($streamData['teacher_id'])) {
                $teacher = Teacher::find($streamData['teacher_id']);
                if (!$teacher || $teacher->school_id !== $user->school_id) {
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'One or more teachers do not belong to your school.',
                    ], 422);
                }
            }
            $assignments[$stream->id] = ['teacher_id' => $streamData['teacher_id'] ?? null];
        }

        $subject->streams()->sync($assignments);

        return response()->json([
            'status'  => 'success',
            'message' => 'Subject assigned to streams successfully.',
            'data'    => $subject->load(['streams']),
        ]);
    }

    public function getTeachers(Request $request, $subjectId)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        if ($this->checkSchoolStreamsEnabled($request)) {
            return response()->json([
                'message' => 'Your school has streams enabled. Teachers are assigned through streams, not directly.',
            ], 403);
        }

        $subject = $this->findSubjectForSchool($subjectId, $user->school_id);
        if ($subject instanceof \Illuminate\Http\JsonResponse) return $subject;

        return response()->json([
            'status'   => 'success',
            'subject'  => $subject,
            'teachers' => $subject->teachers()->with('user')->get(),
        ]);
    }

    public function assignTeachers(Request $request, $subjectId)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();

        if ($this->checkSchoolStreamsEnabled($request)) {
            return response()->json([
                'message' => 'Your school has streams enabled. Assign teachers through streams, not directly.',
            ], 403);
        }

        $subject = $this->findSubjectForSchool($subjectId, $user->school_id);
        if ($subject instanceof \Illuminate\Http\JsonResponse) return $subject;

        $validated = $request->validate([
            'teachers'              => 'required|array',
            'teachers.*.teacher_id' => 'required|integer|exists:teachers,id',
        ]);

        $teacherIds = [];
        foreach ($validated['teachers'] as $td) {
            $teacher = Teacher::find($td['teacher_id']);
            if (!$teacher || $teacher->school_id !== $user->school_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'One or more teachers do not belong to your school.',
                ], 422);
            }
            $teacherIds[] = $teacher->id;
        }

        $subject->teachers()->sync($teacherIds);

        return response()->json([
            'status'  => 'success',
            'message' => 'Teachers assigned successfully.',
            'data'    => $subject->load(['teachers']),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LEGACY ROUTES (kept for backward compat)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/subjects/curriculum/{curriculum}/level/{level}
     */
    public function getByCurriculumAndLevel(Request $request, $curriculum, $level)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();
        if (!$user->school_id) return $this->noSchool();

        $school = School::find($user->school_id);
        if (!$school) return response()->json(['message' => 'School not found.'], 404);

        $err = $this->validateCurriculumForSchool($curriculum, $school);
        if ($err) return $err;

        $schoolLevels = $this->getSchoolLevels($school);
        if (!in_array($level, $schoolLevels)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Your school does not offer the {$level} level.",
            ], 422);
        }

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $query = Subject::with(['school'])
            ->where('school_id', $user->school_id)
            ->where('curriculum_type', $curriculum)
            ->where('level', $level);

        if ($request->filled('grade_level')) {
            $query->where('grade_level', $request->grade_level);
        }

        if ($request->filled('pathway')) {
            if (!$school->offersPathway($request->pathway)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Your school does not offer the {$request->pathway} pathway.",
                ], 422);
            }
            $query->where('pathway', $request->pathway);
        }

        if ($request->filled('category')) $query->where('category', $request->category);
        if ($request->has('is_core')) $query->where('is_core', $request->boolean('is_core'));

        $this->applyRelations($query, $hasStreams);

        return response()->json([
            'status'        => 'success',
            'has_streams'   => $hasStreams,
            'school_levels' => $schoolLevels,
            'data'          => $query->get(),
        ]);
    }

    /**
     * GET /api/subjects/by-school-level
     */
    public function getBySchoolLevel(Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) return $this->unauthorized();
        if (!$user->school_id) return $this->noSchool();

        $school = School::find($user->school_id);
        if (!$school) return response()->json(['message' => 'School not found.'], 404);

        $level = $request->input('level');
        if (!$level) {
            return response()->json(['status' => 'error', 'message' => 'Level parameter is required.'], 400);
        }

        $schoolLevels = $this->getSchoolLevels($school);
        if (!in_array($level, $schoolLevels)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Your school does not offer the {$level} level.",
            ], 422);
        }

        $hasStreams = $this->checkSchoolStreamsEnabled($request);

        $query = Subject::with(['school'])
            ->where('school_id', $user->school_id)
            ->where('level', $level);

        if ($request->filled('grade_level')) $query->where('grade_level', $request->grade_level);
        if ($request->filled('curriculum_type')) {
            $err = $this->validateCurriculumForSchool($request->curriculum_type, $school);
            if ($err) return $err;
            $query->where('curriculum_type', $request->curriculum_type);
        }
        if ($request->filled('pathway')) {
            if (!$school->offersPathway($request->pathway)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Your school does not offer the {$request->pathway} pathway.",
                ], 422);
            }
            $query->where('pathway', $request->pathway);
        }
        if ($request->filled('category')) $query->where('category', $request->category);
        if ($request->has('is_core')) $query->where('is_core', $request->boolean('is_core'));

        $this->applyRelations($query, $hasStreams);

        return response()->json([
            'status'        => 'success',
            'has_streams'   => $hasStreams,
            'school_levels' => $schoolLevels,
            'data'          => $query->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function getUser(Request $request)
    {
        $user = Auth::user();
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }
        return $user;
    }

    private function unauthorized()
    {
        return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
    }

    private function noSchool()
    {
        return response()->json(['message' => 'User is not associated with any school.'], 400);
    }

    private function checkSchoolStreamsEnabled(Request $request): bool
    {
        $user = $this->getUser($request);
        if (!$user) return false;
        $school = School::find($user->school_id);
        return $school ? (bool) $school->has_streams : false;
    }

    private function getSchoolLevels(School $school): array
    {
        $levels = [];
        if ($school->has_pre_primary)    $levels[] = 'Pre-Primary';
        if ($school->has_primary)        $levels[] = 'Primary';
        if ($school->has_junior_secondary) $levels[] = 'Junior Secondary';
        if ($school->has_senior_secondary) $levels[] = 'Senior Secondary';
        if ($school->has_secondary)      $levels[] = 'Secondary';
        return $levels;
    }

    /**
     * Given a grade_level string, return the corresponding educational level.
     */
    private function levelFromGrade(string $gradeLevel): string
    {
        foreach (self::LEVEL_GRADE_MAP as $level => $grades) {
            if (in_array($gradeLevel, $grades)) {
                return $level;
            }
        }
        return 'Secondary'; // Fallback
    }

    /**
     * Validates that the school offers the requested curriculum type.
     * Returns null on success, or a JSON error response on failure.
     */
    private function validateCurriculumForSchool(string $curriculum, School $school)
    {
        $primary   = $school->primary_curriculum;
        $secondary = $school->secondary_curriculum ?? $primary;

        $offers = fn($c) => in_array($primary, [$c, 'Both']) || in_array($secondary, [$c, 'Both']);

        if ($curriculum === 'CBC' && !$offers('CBC')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your school does not offer CBC curriculum.',
            ], 422);
        }

        if ($curriculum === '8-4-4' && !$offers('8-4-4')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your school does not offer 8-4-4 curriculum.',
            ], 422);
        }

        return null;
    }

    /**
     * Attaches the correct eager-load relations depending on whether
     * the school uses streams.
     */
    private function applyRelations($query, bool $hasStreams): void
    {
        if ($hasStreams) {
            $query->with(['streams', 'streams.teachers']);
        } else {
            $query->with(['teachers']);
        }
    }

    /**
     * Finds a subject and verifies it belongs to the given school.
     * Returns the Subject model or a JSON error response.
     */
    private function findSubjectForSchool(int $subjectId, int $schoolId)
    {
        try {
            $subject = Subject::findOrFail($subjectId);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'No subject found with the specified ID.'], 422);
        }

        if ($subject->school_id !== $schoolId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $subject;
    }
}