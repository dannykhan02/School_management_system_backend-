<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\TeacherCombination;
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

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * TeacherController
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FRONTEND ↔ BACKEND SYNC MODEL
 * ──────────────────────────────
 * The TeacherForm operates in two phases when a B.Ed combination is selected:
 *
 *   PHASE 1 — Instant client-side fill (zero API calls)
 *     The frontend uses its embedded COMBINATION_SUBJECT_MAP (mirroring the
 *     TeacherCombinationSeeder) to immediately populate subject pills by name.
 *     No spinner. No wait. Admin sees subjects the moment they select a combo.
 *
 *   PHASE 2 — Background API enrichment
 *     GET /api/teacher-combinations/{id}/preview
 *     Resolves subject names → real school Subject IDs so the form can submit
 *     actual foreign-key IDs. Also flags which combination subjects are not yet
 *     set up in the school's subject table (unmatchedNames).
 *
 * The two APIs that drive this flow:
 *
 *   getCombinations()     → populates the combination <select> dropdown
 *                            returns: id, code, name, degree_title,
 *                                     primary_subjects, derived_subjects,
 *                                     eligible_levels, eligible_pathways
 *
 *   previewCombination()  → called after a combination is chosen
 *                            returns: matched_subjects (with is_primary/is_derived
 *                                     flags + real school subject IDs),
 *                                     unmatched_names (subjects not in school)
 *
 * SUBJECT SUBMISSION FLOW (store / update)
 * ─────────────────────────────────────────
 *   PATH A — combination_id only (no subject_ids)
 *     Backend calls syncFromCombination() which auto-derives subjects, levels,
 *     pathways and specialization from the combination record.
 *
 *   PATH B — combination_id + subject_ids
 *     Combination sets snapshot columns. Manual subject_ids override the pivot.
 *     This is what the form uses when Phase 2 API has resolved real IDs.
 *
 *   PATH C — subject_ids only (no combination)
 *     Pure manual pivot sync. No combination metadata written.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */
class TeacherController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    private function getUser(Request $request): ?User
    {
        $user = Auth::user();
        if (!$user && $request->has('school_id')) {
            $user = User::where('school_id', $request->school_id)->first();
        }
        return $user;
    }

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

    private function setDefaultPassword(User $user): void
    {
        $user->password = Hash::make('teacher123');
        $user->save();
    }

    /**
     * Validate and resolve subject IDs against the school, curriculum, level,
     * and pathway constraints.
     *
     * ✅ FIX: Now allows subjects with NULL curriculum_type or level (global subjects)
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

        // Allow subjects with no curriculum_type set (NULL = applies to all)
        if ($curriculumType && $curriculumType !== 'Both') {
            $query->where(function ($q) use ($curriculumType) {
                $q->where('curriculum_type', $curriculumType)
                  ->orWhereNull('curriculum_type');
            });
        }

        // Allow subjects with no level set (NULL = applies to all levels)
        if (!empty($teachingLevels)) {
            $query->where(function ($q) use ($teachingLevels) {
                $q->whereIn('level', $teachingLevels)
                  ->orWhereNull('level');
            });
        }

        // Pathway: only restrict Senior Secondary subjects that have an explicit pathway
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
     * Build pivot data for qualifiedSubjects sync.
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

    /**
     * Apply combination + subject sync for both store() and update().
     *
     * PATH A: combination_id only         → syncFromCombination()
     * PATH B: combination_id + subject_ids → snapshot combo, sync manual IDs
     * PATH C: subject_ids only            → manual pivot sync
     *
     * Returns ['rejected_ids' => [...], 'used_combination' => bool]
     */
    private function applyCombinationAndSubjects(
        Teacher $teacher,
        array $validated,
        int $schoolId,
        array $teachingLevels,
        array $teachingPathways
    ): array {
        $hasCombination    = !empty($validated['combination_id']);
        $hasManualSubjects = isset($validated['subject_ids']);

        // ── PATH A ───────────────────────────────────────────────────────────
        if ($hasCombination && !$hasManualSubjects) {
            $teacher->load('combination');
            $teacher->syncFromCombination();
            return ['rejected_ids' => [], 'used_combination' => true];
        }

        // ── PATH B — snapshot combo metadata, then fall to manual sync ────────
        if ($hasCombination && $hasManualSubjects) {
            $combo = TeacherCombination::find($validated['combination_id']);
            if ($combo) {
                $teacher->bed_combination_code  = $combo->code;
                $teacher->bed_combination_label = $combo->name;
                $teacher->saveQuietly();
            }
        }

        // ── PATH B + C — manual subject sync ─────────────────────────────────
        $subjectIds = $validated['subject_ids'] ?? [];

        if (empty($subjectIds)) {
            $teacher->qualifiedSubjects()->sync([]);
            $teacher->update(['specialization_subjects' => null]);
            $teacher->load('qualifiedSubjects');
            $teacher->updateSpecializationFromSubjects();
            return ['rejected_ids' => [], 'used_combination' => $hasCombination];
        }

        $validSubjects = $this->resolveValidSubjectIds(
            $subjectIds,
            $schoolId,
            $validated['curriculum_specialization'] ?? null,
            $teachingLevels,
            $teachingPathways
        );

        $rejectedIds = array_diff($subjectIds, $validSubjects->pluck('id')->toArray());

        if (!empty($rejectedIds)) {
            return [
                'rejected_ids'     => array_values($rejectedIds),
                'used_combination' => $hasCombination,
            ];
        }

        $pivotPayload = $this->buildSubjectPivotPayload(
            $validSubjects->pluck('id')->toArray(),
            $validated['subject_pivot_meta'] ?? []
        );

        $teacher->qualifiedSubjects()->sync($pivotPayload);
        $teacher->update(['specialization_subjects' => $validSubjects->pluck('id')->toArray()]);
        $teacher->load('qualifiedSubjects');
        $teacher->updateSpecializationFromSubjects();

        return ['rejected_ids' => [], 'used_combination' => $hasCombination];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PHASE 1 API — COMBINATION LIST
    // GET /api/teacher-combinations
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Return all active TSC-recognised combinations for the form dropdown.
     *
     * The frontend uses this response in two ways:
     *   1. Populate the <select> optgroups (grouped by subject_group).
     *   2. Read primary_subjects / derived_subjects / eligible_levels /
     *      eligible_pathways off each combination object for the instant
     *      info panel rendered below the dropdown BEFORE Phase 2 runs.
     *
     * Therefore every field the COMBINATION_SUBJECT_MAP uses on the frontend
     * MUST be included here so the two sources stay in sync.
     *
     * Query params (all optional):
     *   group      – filter by subject_group e.g. STEM
     *   level      – filter by eligible_level e.g. "Junior Secondary"
     *   pathway    – filter by eligible_pathway e.g. "STEM"
     *   curriculum – filter by curriculum_type e.g. "CBC"
     */
    public function getCombinations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group'      => 'nullable|string',
            'level'      => 'nullable|string',
            'pathway'    => 'nullable|in:STEM,Arts,Social Sciences',
            'curriculum' => 'nullable|in:CBC,8-4-4',
        ]);

        $query = TeacherCombination::active()->tscRecognized();

        if (!empty($validated['group']))      $query->forGroup($validated['group']);
        if (!empty($validated['level']))      $query->forLevel($validated['level']);
        if (!empty($validated['pathway']))    $query->forPathway($validated['pathway']);
        if (!empty($validated['curriculum'])) $query->whereJsonContains('curriculum_types', $validated['curriculum']);

        $combinations = $query->orderBy('subject_group')->orderBy('name')->get();

        // ── Shape each combination for the frontend ───────────────────────────
        // All fields must mirror what TeacherCombinationSeeder seeds so the
        // frontend info panel (Phase 1) and the COMBINATION_SUBJECT_MAP agree.
        $shapeCombo = fn($combo) => [
            'id'                  => $combo->id,
            'code'                => $combo->code,                   // used as COMBINATION_SUBJECT_MAP key
            'name'                => $combo->name,
            'degree_title'        => $combo->degree_title,
            'degree_abbreviation' => $combo->degree_abbreviation,
            'dropdown_label'      => $combo->dropdown_label ?? "{$combo->name} — {$combo->code}",
            'institution_type'    => $combo->institution_type,
            'subject_group'       => $combo->subject_group,
            // ★ These four arrays are used by the frontend Phase 1 info panel
            //   and must exactly match the seeder values:
            'primary_subjects'    => $combo->primary_subjects  ?? [],
            'derived_subjects'    => $combo->derived_subjects  ?? [],
            'eligible_levels'     => $combo->eligible_levels   ?? [],
            'eligible_pathways'   => $combo->eligible_pathways ?? [],
            'curriculum_types'    => $combo->curriculum_types  ?? [],
            'notes'               => $combo->notes,
        ];

        $grouped = $combinations->groupBy('subject_group')
                                ->map(fn($group) => $group->map($shapeCombo));

        return response()->json([
            'status'  => 'success',
            'total'   => $combinations->count(),
            'grouped' => $grouped,
            // ★ `flat` is what the frontend iterates to build the <select>
            //   and to find activeCombination by ID:
            //   const combo = combinations.find(c => c.id === parseInt(value))
            'flat'    => $combinations->map($shapeCombo)->values(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PHASE 2 API — COMBINATION PREVIEW
    // GET /api/teacher-combinations/{id}/preview
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Resolve combination subject NAMES → school Subject IDs.
     *
     * Called by the frontend immediately after Phase 1 instant fill.
     * The frontend already knows the subject names from COMBINATION_SUBJECT_MAP.
     * This endpoint answers: "which of those names exist in THIS school's
     * subjects table, and what are their IDs?"
     *
     * Response contract (frontend depends on this exact shape):
     * {
     *   status: 'success',
     *   combination: { ...snapshot fields },
     *   matched_subjects: [
     *     {
     *       id,            ← real Subject.id the form will submit
     *       name,          ← must match COMBINATION_SUBJECT_MAP name exactly
     *       code,
     *       level,
     *       grade_level,
     *       curriculum_type,
     *       cbc_pathway,
     *       category,
     *       is_core,
     *       is_primary,   ← true if name ∈ combo.primary_subjects
     *       is_derived,   ← true if name ∈ combo.derived_subjects
     *     },
     *     ...
     *   ],
     *   unmatched_names: ['SubjectName', ...],   ← names not in school's DB
     *   summary: { total_teachable, matched_in_school, primary_count, derived_count }
     * }
     *
     * Frontend uses matched_subjects to:
     *   a) Enrich pills with real .id values (for form submission)
     *   b) Build the name→id map for selectedSubjectIds
     *
     * Frontend uses unmatched_names to:
     *   Show the orange "not in your school's records" warning.
     */
    public function previewCombination(Request $request, $combinationId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $combo = TeacherCombination::findOrFail($combinationId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Combination not found.'], 404);
        }

        // All subject names this combination covers (primary + derived)
        $allSubjectNames = $combo->allTeachableSubjects();

        // Match against THIS school's subject records by name
        $matchedSubjects = Subject::where('school_id', $user->school_id)
            ->whereIn('name', $allSubjectNames)
            ->select([
                'id', 'name', 'code', 'level', 'grade_level',
                'curriculum_type', 'cbc_pathway', 'category', 'is_core',
            ])
            ->orderBy('level')
            ->orderBy('name')
            ->get()
            ->map(function ($subject) use ($combo) {
                // ★ These flags let the frontend know which pill colour to use
                //   (cyan = primary, amber = derived) without needing the
                //   COMBINATION_SUBJECT_MAP on the backend.
                $subject->is_primary = in_array($subject->name, $combo->primary_subjects ?? []);
                $subject->is_derived = in_array($subject->name, $combo->derived_subjects ?? []);
                return $subject;
            });

        // Names in the combination that have NO matching subject in this school
        $unmatchedNames = array_values(array_diff(
            $allSubjectNames,
            $matchedSubjects->pluck('name')->toArray()
        ));

        return response()->json([
            'status' => 'success',

            // Snapshot so frontend can cross-check without a second call
            'combination' => [
                'id'                  => $combo->id,
                'code'                => $combo->code,
                'name'                => $combo->name,
                'degree_title'        => $combo->degree_title,
                'degree_abbreviation' => $combo->degree_abbreviation,
                'primary_subjects'    => $combo->primary_subjects  ?? [],
                'derived_subjects'    => $combo->derived_subjects  ?? [],
                'eligible_levels'     => $combo->eligible_levels   ?? [],
                'eligible_pathways'   => $combo->eligible_pathways ?? [],
                'curriculum_types'    => $combo->curriculum_types  ?? [],
            ],

            // ★ Core payload — frontend maps name → id from this array
            'matched_subjects' => $matchedSubjects,

            // ★ Frontend shows orange warning for these
            'unmatched_names'  => $unmatchedNames,

            'summary' => [
                'total_teachable'    => count($allSubjectNames),
                'matched_in_school'  => $matchedSubjects->count(),
                'unmatched_count'    => count($unmatchedNames),
                'primary_count'      => $matchedSubjects->where('is_primary', true)->count(),
                'derived_count'      => $matchedSubjects->where('is_derived', true)->count(),
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CRUD
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/teachers
     *
     * Query params (all optional):
     *   curriculum     – CBC | 8-4-4
     *   level          – filter by teaching_level
     *   pathway        – filter by teaching_pathway
     *   combination_id – filter by B.Ed combination
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $school    = School::find($user->school_id);
        $hasStreams = $school?->has_streams ?? false;

        $query = Teacher::with(['user', 'school', 'combination'])
                        ->where('school_id', $user->school_id);

        if ($hasStreams) {
            $query->with(['classTeacherStreams', 'teachingStreams']);
        } else {
            $query->with(['classrooms' => fn($q) => $q->withPivot('is_class_teacher')]);
        }

        $query->with(['qualifiedSubjects' => fn($q) => $q->withPivot([
            'is_primary_subject', 'years_experience', 'can_teach_levels',
        ])]);

        if ($request->filled('curriculum') && in_array($request->curriculum, ['CBC', '8-4-4'])) {
            $query->where(function ($q) use ($request) {
                $q->where('curriculum_specialization', $request->curriculum)
                  ->orWhere('curriculum_specialization', 'Both');
            });
        }
        if ($request->filled('level'))          $query->whereJsonContains('teaching_levels', $request->level);
        if ($request->filled('pathway'))        $query->whereJsonContains('teaching_pathways', $request->pathway);
        if ($request->filled('combination_id')) $query->where('combination_id', $request->combination_id);

        return response()->json([
            'status'      => 'success',
            'has_streams' => $hasStreams,
            'data'        => $query->get(),
        ]);
    }

    /**
     * POST /api/teachers
     *
     * ── COMBINATION-FIRST (PATH A — recommended) ─────────────────────────────
     *   Send combination_id only. Backend auto-populates subjects, levels,
     *   pathways, and specialization via syncFromCombination().
     *
     *   {
     *     "user_id": 5,
     *     "combination_id": 3,
     *     "bed_graduation_year": 2019,
     *     "bed_awarding_institution": "Kenyatta University",
     *     "curriculum_specialization": "CBC",
     *     "tsc_number": "TSC-12345",
     *     "tsc_status": "registered"
     *   }
     *
     * ── HYBRID (PATH B — used when Phase 2 API has resolved real IDs) ─────────
     *   Send combination_id + subject_ids. Combination sets snapshot metadata;
     *   subject_ids drives the actual pivot (what the form submits after Phase 2).
     *
     * ── MANUAL (PATH C) ───────────────────────────────────────────────────────
     *   Omit combination_id. Provide subject_ids + teaching_levels manually.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $school    = School::find($user->school_id);
        $hasStreams = $school?->has_streams ?? false;

        $curriculumRule = ($school && $school->primary_curriculum === 'Both')
            ? 'required|in:CBC,8-4-4,Both'
            : 'nullable|in:CBC,8-4-4,Both';

        $validated = $request->validate([
            // Identity
            'user_id'                   => 'required|exists:users,id',

            // Combination
            'combination_id'            => 'nullable|exists:teacher_combinations,id',
            'bed_graduation_year'       => 'nullable|integer|min:1970|max:' . date('Y'),
            'bed_institution_type'      => 'nullable|in:university,teacher_training_college,technical_university',
            'bed_awarding_institution'  => 'nullable|string|max:150',

            // Professional
            'qualification'             => 'nullable|string|max:255',
            'employment_type'           => 'nullable|string|max:100',
            'employment_status'         => 'nullable|in:active,on_leave,suspended,resigned,retired',
            'tsc_number'                => 'nullable|string|max:50',
            'tsc_status'                => 'nullable|in:registered,pending,not_registered',
            'specialization'            => 'nullable|string|max:255',
            'curriculum_specialization' => $curriculumRule,

            // Teaching profile
            // NOTE: teaching_levels are NOT auto-filled on the frontend when a
            // combination is selected — admin must tick them manually. The backend
            // fallback ($combo->eligible_levels) is a safety net for direct API
            // callers that send combination_id without teaching_levels.
            'teaching_levels'           => 'nullable|array',
            'teaching_levels.*'         => 'in:Pre-Primary,Primary,Junior Secondary,Senior Secondary',
            'teaching_pathways'         => 'nullable|array',
            'teaching_pathways.*'       => 'in:STEM,Arts,Social Sciences',

            // Subjects — comes from Phase 2 API resolution (real school Subject IDs)
            // When combination_id is provided without subject_ids → PATH A (syncFromCombination)
            // When combination_id is provided with subject_ids   → PATH B (manual override)
            // When only subject_ids are provided                 → PATH C (pure manual)
            'subject_ids'               => 'nullable|array',
            'subject_ids.*'             => 'integer|exists:subjects,id',
            'subject_pivot_meta'        => 'nullable|array',
            'subject_pivot_meta.*'      => 'array',

            // Workload
            'max_subjects'              => 'nullable|integer|min:1|max:20',
            'max_classes'               => 'nullable|integer|min:1|max:20',
            'max_weekly_lessons'        => 'nullable|integer|min:1|max:60',
            'min_weekly_lessons'        => 'nullable|integer|min:1|max:60',
        ]);

        // Resolve combo
        $combo = !empty($validated['combination_id'])
            ? TeacherCombination::find($validated['combination_id'])
            : null;

        // Levels / pathways: explicit input wins; combination is the fallback
        $teachingLevels   = $validated['teaching_levels']   ?? ($combo ? $combo->eligible_levels   : []);
        $teachingPathways = $validated['teaching_pathways'] ?? ($combo ? $combo->eligible_pathways : []);

        // Business rules
        if (!empty($teachingPathways) && !in_array('Senior Secondary', $teachingLevels)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'teaching_pathways can only be set when "Senior Secondary" is in teaching_levels.',
            ], 422);
        }

        $teacherUser = User::findOrFail($validated['user_id']);
        if ($teacherUser->school_id !== $user->school_id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'The user must belong to the same school.',
            ], 422);
        }

        if (empty($validated['curriculum_specialization']) && $school) {
            $validated['curriculum_specialization'] = $school->primary_curriculum;
        }

        if (empty($combo) && empty($teachingLevels)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Either combination_id or teaching_levels must be provided.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $teacher = Teacher::create([
                'user_id'                   => $validated['user_id'],
                'school_id'                 => $user->school_id,

                // Combination snapshot
                'combination_id'            => $validated['combination_id'] ?? null,
                'bed_combination_code'      => $combo?->code,
                'bed_combination_label'     => $combo?->name,
                'bed_graduation_year'       => $validated['bed_graduation_year'] ?? null,
                // institution_type: explicit input wins, fallback to combo's value
                'bed_institution_type'      => $validated['bed_institution_type'] ?? $combo?->institution_type,
                'bed_awarding_institution'  => $validated['bed_awarding_institution'] ?? null,

                // Professional
                // qualification: explicit input wins, fallback to combo's degree_title
                'qualification'             => $validated['qualification'] ?? $combo?->degree_title,
                'employment_type'           => $validated['employment_type'] ?? null,
                'employment_status'         => $validated['employment_status'] ?? 'active',
                'tsc_number'                => $validated['tsc_number'] ?? null,
                'tsc_status'                => $validated['tsc_status'] ?? null,
                'specialization'            => $validated['specialization'] ?? null,
                'curriculum_specialization' => $validated['curriculum_specialization'],

                // Teaching profile
                'teaching_levels'           => $teachingLevels  ?: null,
                'teaching_pathways'         => $teachingPathways ?: null,

                // Workload
                'max_subjects'              => $validated['max_subjects']      ?? null,
                'max_classes'               => $validated['max_classes']       ?? null,
                'max_weekly_lessons'        => $validated['max_weekly_lessons'] ?? 40,
                'min_weekly_lessons'        => $validated['min_weekly_lessons'] ?? 20,
            ]);

            $result = $this->applyCombinationAndSubjects(
                $teacher, $validated, $user->school_id, $teachingLevels, $teachingPathways
            );

            if (!empty($result['rejected_ids'])) {
                DB::rollBack();
                return response()->json([
                    'status'       => 'error',
                    'message'      => 'Some subject IDs do not match the teacher\'s curriculum, level, or pathway.',
                    'rejected_ids' => $result['rejected_ids'],
                ], 422);
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
            'status'           => 'success',
            'message'          => 'Teacher created successfully.',
            'has_streams'      => $hasStreams,
            'used_combination' => !empty($validated['combination_id']),
            'data'             => $teacher->load(['user', 'school', 'combination', 'qualifiedSubjects']),
        ], 201);
    }

    /**
     * GET /api/teachers/{id}
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
            $query = Teacher::with(['user', 'school', 'combination', 'qualifiedSubjects', 'primarySubjects']);

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
        if ($authError) return $authError;

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
     *
     * ── Changing combination + resync ────────────────────────────────────────
     *   Send new combination_id + resync_subjects: true.
     *   syncFromCombination() re-derives subjects from the new combination.
     *
     * ── Changing combination, keeping subjects ────────────────────────────────
     *   Send new combination_id + resync_subjects: false (default).
     *   Only snapshot columns are updated; existing subject pivot is untouched.
     *
     * ── Phase 2 subject update (most common form submit path) ────────────────
     *   Send combination_id + subject_ids (the Phase 2-resolved real IDs).
     *   Snapshot updated, pivot replaced with the submitted IDs.
     *
     * ── Manual subject update only ────────────────────────────────────────────
     *   Omit combination_id, send subject_ids. Only pivot changes.
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
        if ($authError) return $authError;

        $validated = $request->validate([
            // Combination
            'combination_id'            => 'nullable|exists:teacher_combinations,id',
            'resync_subjects'           => 'nullable|boolean',
            'bed_graduation_year'       => 'nullable|integer|min:1970|max:' . date('Y'),
            'bed_institution_type'      => 'nullable|in:university,teacher_training_college,technical_university',
            'bed_awarding_institution'  => 'nullable|string|max:150',

            // Professional
            'qualification'             => 'nullable|string|max:255',
            'employment_type'           => 'nullable|string|max:100',
            'employment_status'         => 'nullable|in:active,on_leave,suspended,resigned,retired',
            'tsc_number'                => 'nullable|string|max:50',
            'tsc_status'                => 'nullable|in:registered,pending,not_registered',
            'specialization'            => 'nullable|string|max:255',
            'curriculum_specialization' => 'nullable|in:CBC,8-4-4,Both',

            // Teaching profile
            'teaching_levels'           => 'nullable|array',
            'teaching_levels.*'         => 'in:Pre-Primary,Primary,Junior Secondary,Senior Secondary',
            'teaching_pathways'         => 'nullable|array',
            'teaching_pathways.*'       => 'in:STEM,Arts,Social Sciences',

            // Subjects (Phase 2 resolved IDs or manual)
            'subject_ids'               => 'nullable|array',
            'subject_ids.*'             => 'integer|exists:subjects,id',
            'subject_pivot_meta'        => 'nullable|array',
            'subject_pivot_meta.*'      => 'array',

            // Workload
            'max_subjects'              => 'nullable|integer|min:1|max:20',
            'max_classes'               => 'nullable|integer|min:1|max:20',
            'max_weekly_lessons'        => 'nullable|integer|min:1|max:60',
            'min_weekly_lessons'        => 'nullable|integer|min:1|max:60',
        ]);

        // Resolve combo for this update
        $combo = null;
        if ($request->has('combination_id')) {
            $combo = !empty($validated['combination_id'])
                ? TeacherCombination::find($validated['combination_id'])
                : null;
        } elseif ($teacher->combination_id) {
            $combo = $teacher->combination;
        }

        // Levels / pathways: explicit input wins; fallback to existing teacher values
        $teachingLevels   = $validated['teaching_levels']   ?? $teacher->teaching_levels   ?? [];
        $teachingPathways = $validated['teaching_pathways'] ?? $teacher->teaching_pathways ?? [];

        if (!empty($teachingPathways) && !in_array('Senior Secondary', $teachingLevels)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'teaching_pathways can only be set when "Senior Secondary" is in teaching_levels.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $updateData = [];

            // Combination snapshot
            if ($request->has('combination_id')) {
                $updateData['combination_id']        = $validated['combination_id'] ?? null;
                $updateData['bed_combination_code']  = $combo?->code;
                $updateData['bed_combination_label'] = $combo?->name;
            }

            if ($request->has('bed_graduation_year'))      $updateData['bed_graduation_year']      = $validated['bed_graduation_year'];
            if ($request->has('bed_institution_type'))     $updateData['bed_institution_type']     = $validated['bed_institution_type'];
            if ($request->has('bed_awarding_institution')) $updateData['bed_awarding_institution'] = $validated['bed_awarding_institution'];

            foreach (['employment_type', 'employment_status', 'tsc_number', 'tsc_status', 'specialization', 'curriculum_specialization'] as $field) {
                if ($request->has($field)) $updateData[$field] = $validated[$field];
            }

            // qualification priority: explicit → new combination degree_title → untouched
            if ($request->has('qualification')) {
                $updateData['qualification'] = $validated['qualification'];
            } elseif ($request->has('combination_id') && $combo) {
                $updateData['qualification'] = $combo->degree_title;
            }

            $updateData['teaching_levels']   = $teachingLevels   ?: null;
            $updateData['teaching_pathways'] = $teachingPathways ?: null;

            foreach (['max_subjects', 'max_classes', 'max_weekly_lessons', 'min_weekly_lessons'] as $field) {
                if ($request->has($field)) $updateData[$field] = $validated[$field];
            }

            $teacher->update($updateData);

            // ── Subject sync decision tree ─────────────────────────────────────
            $resync = $validated['resync_subjects'] ?? false;

            if ($request->has('combination_id') && $resync && !isset($validated['subject_ids'])) {
                // ★ Case 1: New combination + resync flag, no manual override
                //   Let syncFromCombination() re-derive everything from the new combo
                $teacher->load('combination');
                $teacher->syncFromCombination();

            } elseif ($request->has('subject_ids')) {
                // ★ Case 2: Explicit subject_ids sent (Phase 2 resolved IDs or manual)
                //   Works with or without combination_id
                $result = $this->applyCombinationAndSubjects(
                    $teacher, $validated, $user->school_id, $teachingLevels, $teachingPathways
                );

                if (!empty($result['rejected_ids'])) {
                    DB::rollBack();
                    return response()->json([
                        'status'       => 'error',
                        'message'      => 'Some subject IDs do not match the teacher\'s curriculum, level, or pathway.',
                        'rejected_ids' => $result['rejected_ids'],
                    ], 422);
                }

            } else {
                // ★ Case 3: Combination changed but no resync requested, no subject_ids
                //   Only snapshot written above — existing subject pivot is preserved
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
            'data'        => $teacher->load(['user', 'school', 'combination', 'qualifiedSubjects']),
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
        if ($authError) return $authError;

        $teacher->delete();

        return response()->json(['status' => 'success', 'message' => 'Teacher deleted successfully.']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SUBJECT MANAGEMENT
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * GET /api/teachers/{teacherId}/subjects
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
        if ($authError) return $authError;

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

        return response()->json(['status' => 'success', 'data' => $subjects]);
    }

    /**
     * POST /api/teachers/{teacherId}/subjects
     * Add a single subject to the teacher's qualified list.
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
        if ($authError) return $authError;

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

        $teacher->qualifiedSubjects()->syncWithoutDetaching([
            $subject->id => [
                'is_primary_subject' => $validated['is_primary_subject'] ?? false,
                'years_experience'   => $validated['years_experience'] ?? null,
                'can_teach_levels'   => isset($validated['can_teach_levels'])
                    ? json_encode($validated['can_teach_levels'])
                    : null,
            ],
        ]);

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
        if ($authError) return $authError;

        $teacher->qualifiedSubjects()->detach($subjectId);

        $remaining = $teacher->qualifiedSubjects()->pluck('subjects.id')->toArray();
        $teacher->update(['specialization_subjects' => $remaining ?: null]);
        $teacher->load('qualifiedSubjects');
        $teacher->updateSpecializationFromSubjects();

        return response()->json(['status' => 'success', 'message' => 'Subject removed from teacher\'s qualified list.']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // SUBJECT FILTER (for manual picker in the form)
    // GET /api/subjects/filter
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Filter subjects by curriculum / level / pathway for the manual subject
     * picker that appears below the combination pills in the teacher form.
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

        $grouped = $subjects->groupBy('level')->map(
            fn($levelSubjects) => $levelSubjects->groupBy('category')
        );

        return response()->json([
            'status'  => 'success',
            'total'   => $subjects->count(),
            'flat'    => $subjects,
            'grouped' => $grouped,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // VALIDATE ASSIGNMENT (pre-flight check)
    // POST /api/teachers/{teacherId}/validate-assignment
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Pre-flight check before creating a SubjectAssignment.
     * Checks workload, B.Ed combination eligibility, curriculum/level/pathway
     * alignment, and timetable capacity.
     */
    public function validateAssignment(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        try {
            $teacher = Teacher::with('combination')->findOrFail($teacherId);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404);
        }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

        $validated = $request->validate([
            'subject_id'       => 'required|exists:subjects,id',
            'academic_year_id' => 'required|exists:academic_years,id',
            'weekly_periods'   => 'required|integer|min:1',
            'classroom_id'     => 'nullable|exists:classrooms,id',
            'stream_id'        => 'nullable|exists:streams,id',
        ]);

        $warnings = [];
        $errors   = [];

        $subject     = Subject::findOrFail($validated['subject_id']);
        $matchResult = $teacher->checkSubjectMatch($subject);

        // 1. Workload
        $workloadCalculator = new \App\Services\WorkloadCalculator();
        $currentWorkload    = $workloadCalculator->calculate($teacher, $validated['academic_year_id']);
        $newTotalLessons    = $currentWorkload['total_lessons'] + $validated['weekly_periods'];

        if ($newTotalLessons > $teacher->max_weekly_lessons) {
            $errors[] = [
                'type'     => 'workload_exceeded',
                'message'  => "Overloaded — Current: {$currentWorkload['total_lessons']}, Adding: {$validated['weekly_periods']}, Max: {$teacher->max_weekly_lessons}",
                'severity' => 'error',
            ];
        } elseif ($newTotalLessons === $teacher->max_weekly_lessons) {
            $warnings[] = [
                'type'     => 'workload_at_max',
                'message'  => "Teacher will be at maximum capacity ({$teacher->max_weekly_lessons} lessons).",
                'severity' => 'warning',
            ];
        }

        // 2. B.Ed combination eligibility
        $inCombination = false;
        if ($teacher->combination) {
            $inCombination = $teacher->combination->canTeach($subject->name, $subject->level);
            if (!$inCombination) {
                $warnings[] = [
                    'type'     => 'not_in_combination',
                    'message'  => "Subject \"{$subject->name}\" is not in this teacher's B.Ed combination ({$teacher->combination->name}). Assignment still allowed.",
                    'severity' => 'warning',
                ];
            }
        } else {
            $inCombination = $teacher->isQualifiedForSubject($subject);
            if (!$inCombination) {
                $warnings[] = [
                    'type'     => 'subject_not_in_combination',
                    'message'  => "Subject \"{$subject->name}\" is not in this teacher's declared subject combination. Assignment still allowed.",
                    'severity' => 'warning',
                ];
            }
        }

        // 3. Curriculum match
        if ($subject->curriculum_type === 'CBC' && !in_array($teacher->curriculum_specialization, ['CBC', 'Both'])) {
            $warnings[] = ['type' => 'curriculum_warning', 'message' => "Teacher specializes in {$teacher->curriculum_specialization}, not CBC. Assignment still allowed.", 'severity' => 'warning'];
        }
        if ($subject->curriculum_type === '8-4-4' && !in_array($teacher->curriculum_specialization, ['8-4-4', 'Both'])) {
            $warnings[] = ['type' => 'curriculum_warning', 'message' => "Teacher specializes in {$teacher->curriculum_specialization}, not 8-4-4. Assignment still allowed.", 'severity' => 'warning'];
        }

        // 4. Level match
        $teachingLevels = $teacher->teaching_levels ?? [];
        if (!empty($teachingLevels) && !in_array($subject->level, $teachingLevels)) {
            $warnings[] = ['type' => 'level_warning', 'message' => "Subject is for {$subject->level} but teacher covers: " . implode(', ', $teachingLevels) . '. Assignment still allowed.', 'severity' => 'warning'];
        }

        // 5. Pathway match
        $teachingPathways = $teacher->teaching_pathways ?? [];
        if (
            $subject->level === 'Senior Secondary'
            && !empty($teachingPathways)
            && $subject->cbc_pathway
            && $subject->cbc_pathway !== 'All'
            && !in_array($subject->cbc_pathway, $teachingPathways)
        ) {
            $warnings[] = ['type' => 'pathway_warning', 'message' => "Subject belongs to the {$subject->cbc_pathway} pathway but teacher covers: " . implode(', ', $teachingPathways) . '. Assignment still allowed.', 'severity' => 'warning'];
        }

        // 6. Specialization service match
        if (!$matchResult['matches']) {
            $warnings[] = ['type' => 'specialization_warning', 'message' => $matchResult['message'] . ' (assignment still allowed)', 'severity' => 'warning'];
        }

        // 7. Timetable capacity
        $availablePeriods = $teacher->getAvailablePeriods($validated['academic_year_id']);
        if ($availablePeriods < $validated['weekly_periods']) {
            $warnings[] = ['type' => 'insufficient_periods', 'message' => "Teacher may have limited timetable slots ({$availablePeriods} periods available).", 'severity' => 'warning'];
        }

        // 8. Class teacher priority
        $isClassTeacher = ($validated['classroom_id'] ?? null)
            ? $teacher->isClassTeacherFor($validated['classroom_id'])
            : false;

        return response()->json([
            'status' => 'success',
            'valid'  => empty($errors),
            'data'   => [
                'errors'   => $errors,
                'warnings' => $warnings,
                'workload_summary' => [
                    'current_lessons'    => $currentWorkload['total_lessons'],
                    'new_total'          => $newTotalLessons,
                    'max_lessons'        => $teacher->max_weekly_lessons,
                    'available_capacity' => $currentWorkload['available_capacity'] - $validated['weekly_periods'],
                    'status'             => $newTotalLessons > $teacher->max_weekly_lessons ? 'overloaded' : 'acceptable',
                ],
                'combination' => $teacher->combination ? [
                    'id'   => $teacher->combination->id,
                    'name' => $teacher->combination->name,
                    'code' => $teacher->combination->code,
                ] : null,
                'in_combination'       => $inCombination,
                'subject_in_pivot'     => $teacher->isQualifiedForSubject($subject),
                'specialization_match' => $matchResult['matches'],
                'is_class_teacher'     => $isClassTeacher,
                'priority_score'       => $isClassTeacher ? 90 : ($inCombination ? 80 : 50),
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // WORKLOAD ENDPOINTS
    // ──────────────────────────────────────────────────────────────────────────

    public function getWorkload(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try { $teacher = Teacher::findOrFail($teacherId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404); }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

        $validated = $request->validate(['academic_year_id' => 'required|exists:academic_years,id']);

        return response()->json(['status' => 'success', 'data' => $teacher->calculateWorkload($validated['academic_year_id'])]);
    }

    public function getTimetableCapacity(Request $request, $teacherId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        try { $teacher = Teacher::findOrFail($teacherId); }
        catch (ModelNotFoundException) { return response()->json(['status' => 'error', 'message' => 'No teacher found with the specified ID.'], 404); }

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

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

        if (isset($validated['term_id'])) $occupiedQuery->where('term_id', $validated['term_id']);

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

    public function getWorkloadReport(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);

        $validated = $request->validate(['academic_year_id' => 'required|exists:academic_years,id']);

        $teachers   = Teacher::where('school_id', $user->school_id)->with(['user', 'combination'])->get();
        $calculator = new \App\Services\WorkloadCalculator();

        $report = $teachers->map(function ($teacher) use ($calculator, $validated) {
            $workload = $calculator->calculate($teacher, $validated['academic_year_id']);
            return [
                'teacher_id'        => $teacher->id,
                'teacher_name'      => $teacher->user->name ?? 'N/A',
                'combination'       => $teacher->combination?->name,
                'combination_code'  => $teacher->bed_combination_code,
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
            'data'   => ['summary' => $summary, 'teachers' => $report->sortByDesc('total_lessons')->values()],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CLASSROOM / STREAM ASSIGNMENT
    // ──────────────────────────────────────────────────────────────────────────

    public function getTeachersBySchool(Request $request, $schoolId): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        if ($user->school_id != $schoolId) return response()->json(['message' => 'Unauthorized.'], 403);

        $school    = School::find($schoolId);
        $hasStreams = $school?->has_streams ?? false;

        $query = Teacher::with(['user', 'school', 'combination']);
        if ($hasStreams) {
            $query->with(['classTeacherStreams', 'teachingStreams']);
        } else {
            $query->with(['classrooms' => fn($q) => $q->withPivot('is_class_teacher')]);
        }

        return response()->json([
            'status'      => 'success',
            'has_streams' => $hasStreams,
            'data'        => $query->where('school_id', $schoolId)->get(),
        ]);
    }

    public function getAssignments($teacherId): JsonResponse
    {
        $user    = Auth::user();
        $teacher = Teacher::findOrFail($teacherId);

        $authError = $this->checkAuthorization($user, $teacher);
        if ($authError) return $authError;

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

        if (School::find($teacher->school_id)?->has_streams) {
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
            'classroom_id'     => 'required|integer|exists:classrooms,id',
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

        try { $teacher = Teacher::findOrFail($teacherId); }
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
            ->with(['user', 'combination', 'classrooms' => fn($q) => $q->wherePivot('is_class_teacher', true)])
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

        $query = Teacher::with(['user', 'school', 'combination', 'qualifiedSubjects'])
                        ->where('school_id', $user->school_id);

        if ($hasStreams) {
            $query->with(['classTeacherStreams.classroom', 'teachingStreams.classroom']);
        } else {
            $query->with(['classrooms' => fn($q) => $q->withPivot('is_class_teacher')]);
        }

        if ($request->filled('curriculum') && in_array($request->curriculum, ['CBC', '8-4-4'])) {
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
                'combination' => $teacher->combination ? [
                    'id'                  => $teacher->combination->id,
                    'code'                => $teacher->combination->code,
                    'name'                => $teacher->combination->name,
                    'degree_abbreviation' => $teacher->combination->degree_abbreviation,
                    'subject_group'       => $teacher->combination->subject_group,
                    'eligible_pathways'   => $teacher->combination->eligible_pathways,
                ] : null,
                'bed_combination_code'     => $teacher->bed_combination_code,
                'bed_combination_label'    => $teacher->bed_combination_label,
                'bed_graduation_year'      => $teacher->bed_graduation_year,
                'bed_awarding_institution' => $teacher->bed_awarding_institution,
                'qualified_subjects' => $teacher->qualifiedSubjects->map(fn($s) => [
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
                    'full_name'      => $s->classroom ? "{$s->classroom->class_name} - {$s->name}" : $s->name,
                ]);
                $data['teaching_streams'] = $teacher->teachingStreams->map(fn($s) => [
                    'stream_id'      => $s->id,
                    'stream_name'    => $s->name,
                    'classroom_name' => $s->classroom?->class_name ?? '',
                    'full_name'      => $s->classroom ? "{$s->classroom->class_name} - {$s->name}" : $s->name,
                ]);
            } else {
                $data['classrooms'] = $teacher->classrooms->map(fn($c) => [
                    'classroom_id'     => $c->id,
                    'classroom_name'   => $c->class_name,
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