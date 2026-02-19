<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\SubjectAssignment;
use App\Models\User;
use App\Models\School;
use App\Models\Classroom;
use App\Models\Stream;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TeacherController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Resolve authenticated user or fall back to school_id lookup.
     */
    private function getUser(Request $request): ?User
    {
        $user = Auth::user();
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }
        return $user;
    }

    /**
     * Return a 403 response if the teacher does not belong to the user's school.
     */
    private function checkAuthorization(User $user, Teacher $teacher): ?JsonResponse
    {
        if ($teacher->school_id !== $user->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized. This teacher does not belong to your school.',
            ], 403);
        }
        return null;
    }

    /**
     * Set a default password on a newly created teacher user account.
     */
    private function setDefaultPassword(User $user): void
    {
        $user->password = Hash::make('teacher123');
        $user->save();
    }

    /**
     * Validate and resolve subject IDs to ensure they belong to the school
     * AND match the teacher's curriculum / level / pathway profile.
     *
     * Returns a collection of valid Subject models.
     */
    private function resolveValidSubjectIds(
        array $subjectIds,
        int $schoolId,
        ?string $curriculumType,
        array $teachingLevels,
        array $teachingPathways
    ) {
        $query = Subject::whereIn('id', $subjectIds)
                        ->where('school_id', $schoolId);

        // Filter by curriculum type when provided
        if ($curriculumType && $curriculumType !== 'Both') {
            $query->where('curriculum_type', $curriculumType);
        }

        // Filter by level when teaching_levels are specified
        if (!empty($teachingLevels)) {
            $query->whereIn('level', $teachingLevels);
        }

        // Filter by pathway: accept subjects that match a specified pathway OR have no pathway restriction
        if (!empty($teachingPathways)) {
            $query->where(function ($q) use ($teachingPathways) {
                $q->whereIn('cbc_pathway', $teachingPathways)
                  ->orWhere('cbc_pathway', 'All')
                  ->orWhereNull('cbc_pathway');
            });
        }

        return $query->get();
    }

    /**
     * Build pivot data for qualifiedSubjects sync from request input.
     * Expects optional $pivotMeta array keyed by subject_id:
     *   [ subject_id => ['is_primary_subject' => true, 'years_experience' => 5] ]
     */
    private function buildSubjectPivotPayload(array $subjectIds, array $pivotMeta = []): array
    {
        $payload = [];
        foreach ($subjectIds as $index => $subjectId) {
            $meta = $pivotMeta[$subjectId] ?? [];
            $payload[$subjectId] = [
                'is_primary_subject' => $meta['is_primary_subject'] ?? ($index === 0),
                'years_experience'   => $meta['years_experience'] ?? null,
                'can_teach_levels'   => isset($meta['can_teach_levels'])
                    ? json_encode($meta['can_teach_levels'])
                    : null,
            ];
        }
        return $payload;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CRUD
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/teachers
     * List all teachers for the authenticated user's school.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $school    = School::find($user->school_id);
        $hasStreams = $school?->has_streams ?? false;

        $query = Teacher::with(['user', 'school'])
                        ->where('school_id', $user->school_id);

        if ($hasStreams) {
            $query->with(['classTeacherStreams', 'teachingStreams']);
        } else {
            $query->with(['classrooms' => fn($q) => $q->withPivot('is_class_teacher')]);
        }

        // Load qualified subjects with pivot data
        $query->with(['qualifiedSubjects' => fn($q) => $q->withPivot(['is_primary_subject', 'years_experience', 'can_teach_levels'])]);

        // Optional curriculum filter
        if ($request->curriculum && in_array($request->curriculum, ['CBC', '8-4-4'])) {
            $query->where(function ($q) use ($request) {
                $q->where('curriculum_specialization', $request->curriculum)
                  ->orWhere('curriculum_specialization', 'Both');
            });
        }

        // Optional level filter
        if ($request->level) {
            $query->whereJsonContains('teaching_levels', $request->level);
        }

        // Optional pathway filter
        if ($request->pathway) {
            $query->whereJsonContains('teaching_pathways', $request->pathway);
        }

        return response()->json([
            'status'      => 'success',
            'has_streams' => $hasStreams,
            'data'        => $query->get(),
        ]);
    }

    /**
     * POST /api/teachers
     * Create a new teacher with their subject combination, levels, and pathways.
     *
     * Required body fields:
     *   user_id        – int
     *
     * Optional body fields:
     *   qualification, employment_type, employment_status
     *   tsc_number, tsc_status
     *   specialization         – free text e.g. "Mathematics & Physics"
     *   curriculum_specialization – CBC | 8-4-4 | Both
     *   teaching_levels        – array e.g. ["Primary", "Junior Secondary"]
     *   teaching_pathways      – array e.g. ["STEM"] (only for Senior Secondary)
     *   subject_ids            – array of subject IDs the teacher is qualified to teach
     *   subject_pivot_meta     – optional keyed array for per-subject pivot overrides
     *                            { "5": { "is_primary_subject": true, "years_experience": 4 } }
     *   max_subjects, max_classes, max_weekly_lessons, min_weekly_lessons
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $school    = School::find($user->school_id);
        $hasStreams = $school?->has_streams ?? false;

        // ── Validation ────────────────────────────────────────────────────────
        $curriculumRule = ($school && $school->primary_curriculum === 'Both')
            ? 'required|in:CBC,8-4-4,Both'
            : 'nullable|in:CBC,8-4-4,Both';

        $validated = $request->validate([
            'user_id'                  => 'required|exists:users,id',
            'qualification'            => 'nullable|string|max:255',
            'employment_type'          => 'nullable|string|max:100',
            'employment_status'        => 'nullable|in:active,on_leave,suspended,resigned,retired',
            'tsc_number'               => 'nullable|string|max:50',
            'tsc_status'               => 'nullable|in:registered,pending,not_registered',
            'specialization'           => 'nullable|string|max:255',
            'curriculum_specialization' => $curriculumRule,

            // Teaching profile
            'teaching_levels'          => 'nullable|array',
            'teaching_levels.*'        => 'in:Pre-Primary,Primary,Junior Secondary,Senior Secondary',
            'teaching_pathways'        => 'nullable|array',
            'teaching_pathways.*'      => 'in:STEM,Arts,Social Sciences',

            // Subject combination
            'subject_ids'              => 'nullable|array',
            'subject_ids.*'            => 'integer|exists:subjects,id',
            'subject_pivot_meta'       => 'nullable|array',
            'subject_pivot_meta.*'     => 'array',

            // Workload
            'max_subjects'             => 'nullable|integer|min:1|max:20',
            'max_classes'              => 'nullable|integer|min:1|max:20',
            'max_weekly_lessons'       => 'nullable|integer|min:1|max:60',
            'min_weekly_lessons'       => 'nullable|integer|min:1|max:60',
        ]);

        // ── Business rules ────────────────────────────────────────────────────

        // Pathways are only meaningful for Senior Secondary
        $teachingLevels   = $validated['teaching_levels'] ?? [];
        $teachingPathways = $validated['teaching_pathways'] ?? [];

        if (!empty($teachingPathways) && !in_array('Senior Secondary', $teachingLevels)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'teaching_pathways can only be set when "Senior Secondary" is included in teaching_levels.',
            ], 422);
        }

        // The user_id must belong to the same school
        $teacherUser = User::findOrFail($validated['user_id']);
        if ($teacherUser->school_id !== $user->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The user must belong to the same school.',
            ], 422);
        }

        // Default curriculum to school's if not specified
        if (empty($validated['curriculum_specialization']) && $school) {
            $validated['curriculum_specialization'] = $school->primary_curriculum;
        }

        // ── Create teacher inside a transaction ───────────────────────────────
        DB::beginTransaction();
        try {
            $teacher = Teacher::create([
                'user_id'                  => $validated['user_id'],
                'school_id'                => $user->school_id,
                'qualification'            => $validated['qualification'] ?? null,
                'employment_type'          => $validated['employment_type'] ?? null,
                'employment_status'        => $validated['employment_status'] ?? 'active',
                'tsc_number'               => $validated['tsc_number'] ?? null,
                'tsc_status'               => $validated['tsc_status'] ?? null,
                'specialization'           => $validated['specialization'] ?? null,
                'curriculum_specialization' => $validated['curriculum_specialization'],
                'teaching_levels'          => $teachingLevels ?: null,
                'teaching_pathways'        => $teachingPathways ?: null,
                'max_subjects'             => $validated['max_subjects'] ?? null,
                'max_classes'              => $validated['max_classes'] ?? null,
                'max_weekly_lessons'       => $validated['max_weekly_lessons'] ?? 40,
                'min_weekly_lessons'       => $validated['min_weekly_lessons'] ?? 20,
            ]);

            // Attach subject combination
            if (!empty($validated['subject_ids'])) {
                $validSubjects = $this->resolveValidSubjectIds(
                    $validated['subject_ids'],
                    $user->school_id,
                    $validated['curriculum_specialization'] ?? null,
                    $teachingLevels,
                    $teachingPathways
                );

                $rejectedIds = array_diff($validated['subject_ids'], $validSubjects->pluck('id')->toArray());

                if (!empty($rejectedIds)) {
                    DB::rollBack();
                    return response()->json([
                        'status'       => 'error',
                        'message'      => 'Some subject IDs do not match the teacher\'s curriculum, level, or pathway.',
                        'rejected_ids' => array_values($rejectedIds),
                    ], 422);
                }

                $pivotPayload = $this->buildSubjectPivotPayload(
                    $validSubjects->pluck('id')->toArray(),
                    $validated['subject_pivot_meta'] ?? []
                );

                $teacher->qualifiedSubjects()->sync($pivotPayload);

                // Keep the JSON shortcut in sync
                $teacher->update(['specialization_subjects' => $validSubjects->pluck('id')->toArray()]);

                // ✅ NEW: Update the specialization text from subject names
                $teacher->load('qualifiedSubjects');
                $teacher->updateSpecializationFromSubjects();
            }

            $this->setDefaultPassword($teacherUser);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to create teacher: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'      => 'success',
            'message'     => 'Teacher created successfully.',
            'has_streams' => $hasStreams,
            'data'        => $teacher->load(['user', 'school', 'qualifiedSubjects']),
        ], 201);
    }

    /**
     * GET /api/teachers/{id}
     * Show a single teacher with full profile.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $school    = School::find($user->school_id);
        $hasStreams = $school?->has_streams ?? false;

        try {
            $query = Teacher::with(['user', 'school', 'qualifiedSubjects', 'primarySubjects']);

            if ($hasStreams) {
                $query->with(['classTeacherStreams.classroom', 'teachingStreams.classroom']);
            } else {
                $query->with(['classrooms' => fn($q) => $q->withPivot('is_class_teacher')]);
            }

            $teacher = $query->findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        // Decorate stream names
        if ($hasStreams) {
            foreach (['classTeacherStreams', 'teachingStreams'] as $relation) {
                $teacher->$relation->each(function ($stream) {
                    $stream->full_name = $stream->classroom
                        ? "{$stream->classroom->class_name} - {$stream->name}"
                        : $stream->name;
                });
            }
        }

        return response()->json([
            'status'      => 'success',
            'has_streams' => $hasStreams,
            'data'        => $teacher,
        ]);
    }

    /**
     * PUT/PATCH /api/teachers/{id}
     * Update teacher profile including subject combination, levels, and pathways.
     *
     * Sending subject_ids will REPLACE the current qualified subject list (sync).
     * Omitting subject_ids leaves the existing subject list unchanged.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $school    = School::find($user->school_id);
        $hasStreams = $school?->has_streams ?? false;

        try {
            $teacher = Teacher::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $curriculumRule = ($school && $school->primary_curriculum === 'Both')
            ? 'nullable|in:CBC,8-4-4,Both'
            : 'nullable|in:CBC,8-4-4,Both';

        $validated = $request->validate([
            'qualification'             => 'nullable|string|max:255',
            'employment_type'           => 'nullable|string|max:100',
            'employment_status'         => 'nullable|in:active,on_leave,suspended,resigned,retired',
            'tsc_number'                => 'nullable|string|max:50',
            'tsc_status'                => 'nullable|in:registered,pending,not_registered',
            'specialization'            => 'nullable|string|max:255',
            'curriculum_specialization' => $curriculumRule,

            'teaching_levels'           => 'nullable|array',
            'teaching_levels.*'         => 'in:Pre-Primary,Primary,Junior Secondary,Senior Secondary',
            'teaching_pathways'         => 'nullable|array',
            'teaching_pathways.*'       => 'in:STEM,Arts,Social Sciences',

            'subject_ids'               => 'nullable|array',
            'subject_ids.*'             => 'integer|exists:subjects,id',
            'subject_pivot_meta'        => 'nullable|array',
            'subject_pivot_meta.*'      => 'array',

            'max_subjects'              => 'nullable|integer|min:1|max:20',
            'max_classes'               => 'nullable|integer|min:1|max:20',
            'max_weekly_lessons'        => 'nullable|integer|min:1|max:60',
            'min_weekly_lessons'        => 'nullable|integer|min:1|max:60',
        ]);

        // Resolve final levels and pathways (merge with existing if not provided)
        $teachingLevels   = $validated['teaching_levels']   ?? $teacher->teaching_levels   ?? [];
        $teachingPathways = $validated['teaching_pathways'] ?? $teacher->teaching_pathways ?? [];

        if (!empty($teachingPathways) && !in_array('Senior Secondary', $teachingLevels)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'teaching_pathways can only be set when "Senior Secondary" is included in teaching_levels.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $teacher->update(array_filter([
                'qualification'             => $validated['qualification']             ?? $teacher->qualification,
                'employment_type'           => $validated['employment_type']           ?? $teacher->employment_type,
                'employment_status'         => $validated['employment_status']         ?? $teacher->employment_status,
                'tsc_number'                => $validated['tsc_number']                ?? $teacher->tsc_number,
                'tsc_status'                => $validated['tsc_status']                ?? $teacher->tsc_status,
                'specialization'            => $validated['specialization']            ?? $teacher->specialization,
                'curriculum_specialization' => $validated['curriculum_specialization'] ?? $teacher->curriculum_specialization,
                'teaching_levels'           => $teachingLevels  ?: null,
                'teaching_pathways'         => $teachingPathways ?: null,
                'max_subjects'              => $validated['max_subjects']              ?? $teacher->max_subjects,
                'max_classes'               => $validated['max_classes']               ?? $teacher->max_classes,
                'max_weekly_lessons'        => $validated['max_weekly_lessons']        ?? $teacher->max_weekly_lessons,
                'min_weekly_lessons'        => $validated['min_weekly_lessons']        ?? $teacher->min_weekly_lessons,
            ], fn($v) => $v !== null));

            // Update subject combination only if subject_ids was explicitly sent
            if ($request->has('subject_ids')) {
                $subjectIds = $validated['subject_ids'] ?? [];

                if (!empty($subjectIds)) {
                    $curriculumType = $validated['curriculum_specialization'] ?? $teacher->curriculum_specialization;
                    $validSubjects  = $this->resolveValidSubjectIds(
                        $subjectIds,
                        $user->school_id,
                        $curriculumType,
                        $teachingLevels,
                        $teachingPathways
                    );

                    $rejectedIds = array_diff($subjectIds, $validSubjects->pluck('id')->toArray());
                    if (!empty($rejectedIds)) {
                        DB::rollBack();
                        return response()->json([
                            'status'       => 'error',
                            'message'      => 'Some subject IDs do not match the teacher\'s curriculum, level, or pathway.',
                            'rejected_ids' => array_values($rejectedIds),
                        ], 422);
                    }

                    $pivotPayload = $this->buildSubjectPivotPayload(
                        $validSubjects->pluck('id')->toArray(),
                        $validated['subject_pivot_meta'] ?? []
                    );
                    $teacher->qualifiedSubjects()->sync($pivotPayload);
                    $teacher->update(['specialization_subjects' => $validSubjects->pluck('id')->toArray()]);
                } else {
                    // Sending an empty array clears all subject assignments
                    $teacher->qualifiedSubjects()->sync([]);
                    $teacher->update(['specialization_subjects' => null]);
                }

                // ✅ NEW: Update the specialization text from subject names
                $teacher->load('qualifiedSubjects');
                $teacher->updateSpecializationFromSubjects();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Failed to update teacher: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'status'      => 'success',
            'message'     => 'Teacher updated successfully.',
            'has_streams' => $hasStreams,
            'data'        => $teacher->load(['user', 'school', 'qualifiedSubjects']),
        ]);
    }

    /**
     * DELETE /api/teachers/{id}
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($id);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $teacher->delete();

        return response()->json(['status' => 'success', 'message' => 'Teacher deleted successfully.']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SUBJECT COMBINATION MANAGEMENT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/teachers/{teacherId}/subjects
     * Return the teacher's full qualified subject list with pivot data.
     */
    public function getSubjects(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $subjects = $teacher->qualifiedSubjects()
                            ->withPivot(['is_primary_subject', 'years_experience', 'can_teach_levels'])
                            ->get()
                            ->map(fn($subject) => [
                                'id'                 => $subject->id,
                                'name'               => $subject->name,
                                'code'               => $subject->code,
                                'level'              => $subject->level,
                                'grade_level'        => $subject->grade_level,
                                'curriculum_type'    => $subject->curriculum_type,
                                'cbc_pathway'        => $subject->cbc_pathway,
                                'category'           => $subject->category,
                                'is_core'            => $subject->is_core,
                                'is_primary_subject' => (bool) $subject->pivot->is_primary_subject,
                                'years_experience'   => $subject->pivot->years_experience,
                                'can_teach_levels'   => $subject->pivot->can_teach_levels
                                    ? json_decode($subject->pivot->can_teach_levels)
                                    : null,
                            ]);

        return response()->json([
            'status' => 'success',
            'data'   => $subjects,
        ]);
    }

    /**
     * POST /api/teachers/{teacherId}/subjects
     * Add a single subject to the teacher's qualified list.
     *
     * Body: { subject_id, is_primary_subject?, years_experience?, can_teach_levels? }
     */
    public function addSubject(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $validated = $request->validate([
            'subject_id'         => 'required|integer|exists:subjects,id',
            'is_primary_subject' => 'nullable|boolean',
            'years_experience'   => 'nullable|integer|min:0|max:50',
            'can_teach_levels'   => 'nullable|array',
            'can_teach_levels.*' => 'in:Pre-Primary,Primary,Junior Secondary,Senior Secondary',
        ]);

        $subject = Subject::where('id', $validated['subject_id'])
                          ->where('school_id', $user->school_id)
                          ->first();

        if (!$subject) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Subject not found or does not belong to your school.',
            ], 404);
        }

        // Attach (syncWithoutDetaching prevents duplicate)
        $teacher->qualifiedSubjects()->syncWithoutDetaching([
            $subject->id => [
                'is_primary_subject' => $validated['is_primary_subject'] ?? false,
                'years_experience'   => $validated['years_experience'] ?? null,
                'can_teach_levels'   => isset($validated['can_teach_levels'])
                    ? json_encode($validated['can_teach_levels'])
                    : null,
            ],
        ]);

        // ✅ NEW: Update specialization after adding a subject
        $teacher->load('qualifiedSubjects');
        $teacher->updateSpecializationFromSubjects();

        return response()->json([
            'status'  => 'success',
            'message' => 'Subject added to teacher\'s qualified list.',
            'data'    => $teacher->load('qualifiedSubjects'),
        ]);
    }

    /**
     * DELETE /api/teachers/{teacherId}/subjects/{subjectId}
     * Remove a subject from the teacher's qualified list.
     */
    public function removeSubject(Request $request, $teacherId, $subjectId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $teacher->qualifiedSubjects()->detach($subjectId);

        // Sync the JSON shortcut column
        $remaining = $teacher->qualifiedSubjects()->pluck('subject_id')->toArray();
        $teacher->update(['specialization_subjects' => $remaining ?: null]);

        // ✅ NEW: Update specialization after removing a subject
        $teacher->load('qualifiedSubjects');
        $teacher->updateSpecializationFromSubjects();

        return response()->json(['status' => 'success', 'message' => 'Subject removed from teacher\'s qualified list.']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SUBJECT FILTERING HELPER (for frontend form population)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/subjects/filter
     * Returns subjects filtered by curriculum, level, and pathway.
     * Used by the frontend when building the "Add/Edit Teacher" form.
     *
     * Query params: curriculum, level, pathway
     */
    public function filterSubjectsForTeacher(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $validated = $request->validate([
            'curriculum' => 'nullable|in:CBC,8-4-4,Both',
            'level'      => 'nullable|string',
            'pathway'    => 'nullable|in:STEM,Arts,Social Sciences,All',
        ]);

        $query = Subject::where('school_id', $user->school_id);

        if (!empty($validated['curriculum']) && $validated['curriculum'] !== 'Both') {
            $query->where('curriculum_type', $validated['curriculum']);
        }

        if (!empty($validated['level'])) {
            $query->where('level', $validated['level']);
        }

        if (!empty($validated['pathway']) && $validated['pathway'] !== 'All') {
            $query->where(function ($q) use ($validated) {
                $q->where('cbc_pathway', $validated['pathway'])
                  ->orWhere('cbc_pathway', 'All')
                  ->orWhereNull('cbc_pathway');
            });
        }

        $subjects = $query->select([
            'id', 'name', 'code', 'level', 'grade_level',
            'curriculum_type', 'cbc_pathway', 'category', 'is_core',
            'learning_area', 'minimum_weekly_periods', 'maximum_weekly_periods',
        ])->orderBy('level')->orderBy('category')->orderBy('name')->get();

        // Group by level → category for easier frontend consumption
        $grouped = $subjects->groupBy('level')->map(
            fn($levelSubjects) => $levelSubjects->groupBy('category')
        );

        return response()->json([
            'status'   => 'success',
            'total'    => $subjects->count(),
            'flat'     => $subjects,
            'grouped'  => $grouped,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // WORKLOAD
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/teachers/{teacherId}/workload?academic_year_id=1
     */
    public function getWorkload(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $validated = $request->validate(['academic_year_id' => 'required|exists:academic_years,id']);

        return response()->json([
            'status' => 'success',
            'data'   => $teacher->calculateWorkload($validated['academic_year_id']),
        ]);
    }

    /**
     * GET /api/teachers/{teacherId}/timetable-capacity?academic_year_id=1&term_id=1
     */
    public function getTimetableCapacity(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $validated = $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'term_id'          => 'nullable|exists:terms,id',
        ]);

        $availablePeriods = $teacher->getAvailablePeriods(
            $validated['academic_year_id'],
            $validated['term_id'] ?? null
        );

        $occupiedQuery = $teacher->timetablePeriods()
                                 ->where('academic_year_id', $validated['academic_year_id']);

        if (isset($validated['term_id'])) {
            $occupiedQuery->where('term_id', $validated['term_id']);
        }

        $occupiedPeriods = $occupiedQuery->with(['subjectAssignment.subject'])->get();
        $conflicts       = $occupiedPeriods->where('has_conflict', true);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'available_periods' => $availablePeriods,
                'occupied_periods'  => $occupiedPeriods->count(),
                'total_periods'     => 40,
                'has_conflicts'     => $conflicts->isNotEmpty(),
                'conflicts'         => $conflicts->map(fn($p) => [
                    'day'     => $p->day_of_week,
                    'period'  => $p->period_number,
                    'subject' => $p->subjectAssignment->subject->name ?? 'Unknown',
                    'details' => $p->conflict_details,
                ]),
                'schedule' => $occupiedPeriods->groupBy('day_of_week')->map(
                    fn($periods) => $periods->map(fn($p) => [
                        'period_number' => $p->period_number,
                        'start_time'    => $p->start_time,
                        'end_time'      => $p->end_time,
                        'subject'       => $p->subjectAssignment->subject->name ?? 'Unknown',
                    ])->sortBy('period_number')->values()
                ),
            ],
        ]);
    }

    /**
     * POST /api/teachers/{teacherId}/validate-assignment
     * Pre-flight check before creating a SubjectAssignment.
     */
    public function validateAssignment(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::findOrFail($teacherId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $validated = $request->validate([
            'subject_id'       => 'required|exists:subjects,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'weekly_periods'   => 'required|integer|min:1',
            'classroom_id'     => 'nullable|exists:classrooms,id',
            'stream_id'        => 'nullable|exists:streams,id',
        ]);

        $warnings = [];
        $errors   = [];

        // 1. Workload check
        $workloadCalculator = new \App\Services\WorkloadCalculator();
        $currentWorkload    = $workloadCalculator->calculate($teacher, $validated['academic_year_id']);
        $newTotalLessons    = $currentWorkload['total_lessons'] + $validated['weekly_periods'];

        if ($newTotalLessons > $teacher->max_weekly_lessons) {
            $errors[] = [
                'type'     => 'workload_exceeded',
                'message'  => "Teacher will be overloaded. Current: {$currentWorkload['total_lessons']}, Adding: {$validated['weekly_periods']}, Max: {$teacher->max_weekly_lessons}",
                'severity' => 'error',
            ];
        } elseif ($newTotalLessons === $teacher->max_weekly_lessons) {
            $warnings[] = [
                'type'     => 'workload_at_max',
                'message'  => "Teacher will be at maximum capacity ({$teacher->max_weekly_lessons} lessons).",
                'severity' => 'warning',
            ];
        }

        $subject = Subject::findOrFail($validated['subject_id']);

        // 2. Check teacher has declared this subject in their qualified list
        if (!$teacher->isQualifiedForSubject($subject)) {
            $warnings[] = [
                'type'     => 'subject_not_in_combination',
                'message'  => "Subject \"{$subject->name}\" is not in this teacher's declared subject combination. (Assignment still allowed)",
                'severity' => 'warning',
            ];
        }

        // 3. Check curriculum match
        if ($subject->curriculum_type === 'CBC' && !in_array($teacher->curriculum_specialization, ['CBC', 'Both'])) {
            $warnings[] = [
                'type'     => 'curriculum_warning',
                'message'  => "Teacher's curriculum specialization ({$teacher->curriculum_specialization}) does not include CBC. (Assignment still allowed)",
                'severity' => 'warning',
            ];
        }

        if ($subject->curriculum_type === '8-4-4' && !in_array($teacher->curriculum_specialization, ['8-4-4', 'Both'])) {
            $warnings[] = [
                'type'     => 'curriculum_warning',
                'message'  => "Teacher's curriculum specialization ({$teacher->curriculum_specialization}) does not include 8-4-4. (Assignment still allowed)",
                'severity' => 'warning',
            ];
        }

        // 4. Check level match
        $teachingLevels = $teacher->teaching_levels ?? [];
        if (!empty($teachingLevels) && !in_array($subject->level, $teachingLevels)) {
            $warnings[] = [
                'type'     => 'level_warning',
                'message'  => "Subject \"{$subject->name}\" is for {$subject->level} but teacher teaches: " . implode(', ', $teachingLevels) . ". (Assignment still allowed)",
                'severity' => 'warning',
            ];
        }

        // 5. Check pathway match (Senior Secondary only)
        $teachingPathways = $teacher->teaching_pathways ?? [];
        if (
            $subject->level === 'Senior Secondary'
            && !empty($teachingPathways)
            && $subject->cbc_pathway
            && $subject->cbc_pathway !== 'All'
            && !in_array($subject->cbc_pathway, $teachingPathways)
        ) {
            $warnings[] = [
                'type'     => 'pathway_warning',
                'message'  => "Subject \"{$subject->name}\" belongs to the {$subject->cbc_pathway} pathway, but teacher covers: " . implode(', ', $teachingPathways) . ". (Assignment still allowed)",
                'severity' => 'warning',
            ];
        }

        // 6. Specialization match (via SpecializationMatcher service)
        $matchResult = $teacher->checkSubjectMatch($subject);
        if (!$matchResult['matches']) {
            $warnings[] = [
                'type'     => 'specialization_warning',
                'message'  => $matchResult['message'] . ' (assignment still allowed)',
                'severity' => 'warning',
            ];
        }

        // 7. Timetable capacity (soft warning only)
        $availablePeriods  = $teacher->getAvailablePeriods($validated['academic_year_id']);
        $maxPossiblePeriods = 40;
        if ($availablePeriods < $maxPossiblePeriods && $availablePeriods < $validated['weekly_periods']) {
            $warnings[] = [
                'type'     => 'insufficient_periods',
                'message'  => "Teacher may have limited timetable slots ({$availablePeriods} periods available).",
                'severity' => 'warning',
            ];
        }

        // 8. Class teacher priority
        $isClassTeacher = false;
        if ($validated['classroom_id'] ?? null) {
            $isClassTeacher = $teacher->isClassTeacherFor($validated['classroom_id']);
        }

        return response()->json([
            'status' => 'success',
            'valid'  => empty($errors),
            'data'   => [
                'errors'                => $errors,
                'warnings'              => $warnings,
                'workload_summary'      => [
                    'current_lessons'   => $currentWorkload['total_lessons'],
                    'new_total'         => $newTotalLessons,
                    'max_lessons'       => $teacher->max_weekly_lessons,
                    'available_capacity' => $currentWorkload['available_capacity'] - $validated['weekly_periods'],
                    'status'            => $newTotalLessons > $teacher->max_weekly_lessons ? 'overloaded' : 'acceptable',
                ],
                'subject_in_combination' => $teacher->isQualifiedForSubject($subject),
                'specialization_match'   => $matchResult['matches'],
                'is_class_teacher'       => $isClassTeacher,
                'priority_score'         => $isClassTeacher ? 90 : 50,
            ],
        ]);
    }

    /**
     * GET /api/teachers/workload-report?academic_year_id=1
     */
    public function getWorkloadReport(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $validated = $request->validate(['academic_year_id' => 'required|exists:academic_years,id']);

        $teachers   = Teacher::where('school_id', $user->school_id)->with('user')->get();
        $calculator = new \App\Services\WorkloadCalculator();

        $report = $teachers->map(function ($teacher) use ($calculator, $validated) {
            $workload = $calculator->calculate($teacher, $validated['academic_year_id']);
            return [
                'teacher_id'        => $teacher->id,
                'teacher_name'      => $teacher->user->name ?? 'N/A',
                'teaching_levels'   => $teacher->teaching_levels,
                'teaching_pathways' => $teacher->teaching_pathways,
                'total_lessons'     => $workload['total_lessons'],
                'subject_count'     => $workload['subject_count'],
                'classroom_count'   => $workload['classroom_count'],
                'status'            => $workload['status'],
                'percentage_used'   => $workload['percentage_used'],
                'is_overloaded'     => $workload['is_overloaded'],
                'is_underloaded'    => $workload['is_underloaded'],
            ];
        });

        $summary = [
            'total_teachers'   => $report->count(),
            'overloaded'       => $report->where('is_overloaded', true)->count(),
            'underloaded'      => $report->where('is_underloaded', true)->count(),
            'optimal'          => $report->where('status', 'optimal')->count(),
            'average_workload' => round($report->avg('total_lessons'), 1),
        ];

        return response()->json([
            'status' => 'success',
            'data'   => [
                'summary'  => $summary,
                'teachers' => $report->sortByDesc('total_lessons')->values(),
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CLASSROOM / STREAM ASSIGNMENT METHODS  (unchanged logic, kept for completeness)
    // ──────────────────────────────────────────────────────────────────────────

    public function getTeachersBySchool(Request $request, $schoolId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }
        if ($user->school_id != $schoolId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $school    = School::find($schoolId);
        $hasStreams = $school?->has_streams ?? false;

        $query = Teacher::with(['user', 'school']);
        if ($hasStreams) {
            $query->with(['classTeacherStreams', 'teachingStreams']);
        } else {
            $query->with(['classrooms' => fn($q) => $q->withPivot('is_class_teacher')]);
        }
        $teachers = $query->where('school_id', $schoolId)->get();

        return response()->json(['status' => 'success', 'has_streams' => $hasStreams, 'data' => $teachers]);
    }

    public function getAssignments($teacherId): JsonResponse
    {
        $user    = Auth::user();
        $teacher = Teacher::findOrFail($teacherId);

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) {
            return $authError;
        }

        $assignments = SubjectAssignment::where('teacher_id', $teacherId)
                                        ->with(['subject', 'academicYear', 'stream.classroom'])
                                        ->get();

        return response()->json(['teacher' => $teacher, 'assignments' => $assignments]);
    }

    public function getClassrooms(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try { $teacher = Teacher::findOrFail($teacherId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404); }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

        $school = School::find($teacher->school_id);
        if ($school?->has_streams) {
            return response()->json(['message' => 'Your school has streams enabled. Teachers are assigned to streams, not classrooms.'], 403);
        }

        return response()->json(['status' => 'success', 'data' => $teacher->classrooms()->withPivot('is_class_teacher')->get()]);
    }

    public function assignToClassroom(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try { $teacher = Teacher::findOrFail($teacherId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404); }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

        $school = School::find($teacher->school_id);
        if ($school?->has_streams) {
            return response()->json(['message' => 'Your school has streams enabled. Teachers should be assigned to streams, not classrooms.'], 403);
        }

        $validated = $request->validate([
            'classroom_id'    => 'required|integer|exists:classrooms,id',
            'is_class_teacher' => 'nullable|boolean',
        ]);

        $classroom = Classroom::findOrFail($validated['classroom_id']);
        if ($classroom->school_id !== $teacher->school_id) {
            return response()->json(['status' => 'error', 'message' => 'The classroom does not belong to the same school as the teacher.'], 403);
        }

        if ($validated['is_class_teacher'] ?? false) {
            $existing = Classroom::whereHas('teachers', fn($q) =>
                $q->where('teacher_id', $teacherId)->where('is_class_teacher', true)
            )->where('id', '!=', $classroom->id)->first();

            if ($existing) {
                return response()->json([
                    'status'             => 'error',
                    'message'            => 'This teacher is already a class teacher for another classroom.',
                    'existing_classroom' => $existing->class_name,
                ], 422);
            }
        }

        $teacher->classrooms()->syncWithoutDetaching([
            $classroom->id => ['is_class_teacher' => $validated['is_class_teacher'] ?? false],
        ]);

        return response()->json(['status' => 'success', 'message' => 'Teacher assigned to classroom successfully.', 'data' => $teacher->load('classrooms')]);
    }

    public function removeFromClassroom(Request $request, $teacherId, $classroomId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try { $teacher   = Teacher::findOrFail($teacherId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404); }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

        $school = School::find($teacher->school_id);
        if ($school?->has_streams) {
            return response()->json(['message' => 'Your school has streams enabled. Teachers should be removed from streams, not classrooms.'], 403);
        }

        try { $classroom = Classroom::findOrFail($classroomId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No classroom found with the specified ID.'], 404); }

        if ($classroom->school_id !== $teacher->school_id) {
            return response()->json(['status' => 'error', 'message' => 'The classroom does not belong to the same school as the teacher.'], 403);
        }

        $teacher->classrooms()->detach($classroom->id);

        return response()->json(['status' => 'success', 'message' => 'Teacher removed from classroom successfully.']);
    }

    public function getAllClassTeachers(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        $school = School::find($user->school_id);
        if ($school?->has_streams) {
            return response()->json(['message' => 'Your school has streams enabled. Class teachers are assigned to streams, not classrooms.'], 403);
        }

        $classTeachers = Teacher::whereHas('classrooms', fn($q) => $q->where('is_class_teacher', true))
            ->with(['user', 'classrooms' => fn($q) => $q->wherePivot('is_class_teacher', true)])
            ->where('school_id', $user->school_id)
            ->get();

        return response()->json(['status' => 'success', 'data' => $classTeachers]);
    }

    public function getStreamsAsClassTeacher(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try { $teacher = Teacher::findOrFail($teacherId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404); }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

        if (!School::find($teacher->school_id)?->has_streams) {
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);
        }

        return response()->json(['status' => 'success', 'data' => $teacher->getStreamsAsClassTeacher()]);
    }

    public function getStreamsAsTeacher(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try { $teacher = Teacher::findOrFail($teacherId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404); }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

        if (!School::find($teacher->school_id)?->has_streams) {
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);
        }

        return response()->json(['status' => 'success', 'data' => $teacher->getStreamsAsTeacher()]);
    }

    public function getTeachersByStream(Request $request, $streamId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try { $stream = Stream::findOrFail($streamId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No stream found with the specified ID.'], 404); }

        if ($stream->school_id !== $user->school_id) return response()->json(['message' => 'Unauthorized.'], 403);

        if (!School::find($user->school_id)?->has_streams) {
            return response()->json(['message' => 'Your school does not have streams enabled.'], 403);
        }

        return response()->json(['status' => 'success', 'data' => $stream->teachers()->with('user')->get()]);
    }

    public function getTeachersWithAssignments(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        $school    = School::find($user->school_id);
        $hasStreams = $school?->has_streams ?? false;

        $query = Teacher::with(['user', 'school', 'qualifiedSubjects'])
                        ->where('school_id', $user->school_id);

        if ($hasStreams) {
            $query->with(['classTeacherStreams.classroom', 'teachingStreams.classroom']);
        } else {
            $query->with(['classrooms' => fn($q) => $q->withPivot('is_class_teacher')]);
        }

        if ($request->curriculum && in_array($request->curriculum, ['CBC', '8-4-4'])) {
            $query->where(function ($q) use ($request) {
                $q->where('curriculum_specialization', $request->curriculum)
                  ->orWhere('curriculum_specialization', 'Both');
            });
        }

        $teachers = $query->get()->map(function ($teacher) use ($hasStreams) {
            $data = [
                'id'                        => $teacher->id,
                'name'                      => $teacher->user?->full_name ?? 'N/A',
                'email'                     => $teacher->user?->email ?? 'N/A',
                'phone'                     => $teacher->user?->phone ?? 'N/A',
                'qualification'             => $teacher->qualification,
                'employment_type'           => $teacher->employment_type,
                'employment_status'         => $teacher->employment_status,
                'tsc_number'                => $teacher->tsc_number,
                'tsc_status'                => $teacher->tsc_status,
                'specialization'            => $teacher->specialization,
                'curriculum_specialization' => $teacher->curriculum_specialization,
                'teaching_levels'           => $teacher->teaching_levels,
                'teaching_pathways'         => $teacher->teaching_pathways,
                'max_subjects'              => $teacher->max_subjects,
                'max_classes'               => $teacher->max_classes,
                'max_weekly_lessons'        => $teacher->max_weekly_lessons,
                'min_weekly_lessons'        => $teacher->min_weekly_lessons,
                'qualified_subjects'        => $teacher->qualifiedSubjects->map(fn($s) => [
                    'id'                 => $s->id,
                    'name'               => $s->name,
                    'code'               => $s->code,
                    'level'              => $s->level,
                    'cbc_pathway'        => $s->cbc_pathway,
                    'is_primary_subject' => (bool) $s->pivot->is_primary_subject,
                    'years_experience'   => $s->pivot->years_experience,
                ]),
            ];

            if ($hasStreams) {
                $data['class_teacher_streams'] = $teacher->classTeacherStreams->map(fn($s) => [
                    'stream_id'      => $s->id,
                    'stream_name'    => $s->name,
                    'classroom_name' => $s->classroom?->class_name ?? '',
                    'full_name'      => $s->classroom
                        ? "{$s->classroom->class_name} - {$s->name}"
                        : $s->name,
                ]);
                $data['teaching_streams'] = $teacher->teachingStreams->map(fn($s) => [
                    'stream_id'      => $s->id,
                    'stream_name'    => $s->name,
                    'classroom_name' => $s->classroom?->class_name ?? '',
                    'full_name'      => $s->classroom
                        ? "{$s->classroom->class_name} - {$s->name}"
                        : $s->name,
                ]);
            } else {
                $data['classrooms'] = $teacher->classrooms->map(fn($c) => [
                    'classroom_id'    => $c->id,
                    'classroom_name'  => $c->class_name,
                    'is_class_teacher' => (bool) $c->pivot->is_class_teacher,
                ]);
                $data['class_teacher_classrooms'] = $teacher->classrooms
                    ->where('pivot.is_class_teacher', true)
                    ->map(fn($c) => ['classroom_id' => $c->id, 'classroom_name' => $c->class_name])
                    ->values();
            }

            return $data;
        });

        return response()->json([
            'status'      => 'success',
            'has_streams' => $hasStreams,
            'school_name' => $school?->school_name ?? 'N/A',
            'data'        => $teachers,
        ]);
    }
}