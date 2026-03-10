<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AcademicYearController extends Controller
{
    /**
     * Deactivate all terms whose year is strictly in the past for this school.
     * Called on index() so the DB stays consistent every time the list is loaded.
     */
    private function deactivatePastYears(int $schoolId): void
    {
        $currentYear = (int) date('Y');

        AcademicYear::where('school_id', $schoolId)
            ->whereRaw('CAST(year AS UNSIGNED) < ?', [$currentYear])
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * Display all academic years for the logged-in user's school.
     * Auto-deactivates any past-year terms in the DB before returning the list.
     */
    public function index()
    {
        $user = Auth::user();

        // Ensure past years are inactive in the DB before returning data
        $this->deactivatePastYears($user->school_id);

        return AcademicYear::where('school_id', $user->school_id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Create a new academic year (single term).
     * Enforces is_active = false for past years at the DB level.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $school = School::find($user->school_id);

        if (!$school) {
            return response()->json(['message' => 'School not found'], 404);
        }

        $validationRules = [
            'year'       => 'required|string|max:255',
            'term'       => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'is_active'  => 'sometimes|boolean',
        ];

        if ($school->primary_curriculum === 'Both') {
            $validationRules['curriculum_type'] = 'required|in:CBC,8-4-4';
        } else {
            $validationRules['curriculum_type'] = 'sometimes|in:CBC,8-4-4';
        }

        $validated = $request->validate($validationRules);

        if ($school->primary_curriculum !== 'Both') {
            $validated['curriculum_type'] = $school->primary_curriculum;
        }

        $validated['school_id'] = $user->school_id;

        // Force inactive for past years regardless of what was sent
        if ((int) $validated['year'] < (int) date('Y')) {
            $validated['is_active'] = false;
        }

        return AcademicYear::create($validated);
    }

    /**
     * Create multiple terms for a given year in one action.
     * Enforces is_active = false for all terms when the year is in the past.
     *
     * Expected payload:
     * {
     *   "year": "2026",
     *   "curriculum_type": "CBC",          // required only for "Both" curriculum schools
     *   "terms": [
     *     { "term": "Term 1", "start_date": "2026-01-05", "end_date": "2026-04-03", "is_active": true },
     *     { "term": "Term 2", "start_date": "2026-05-04", "end_date": "2026-07-31" },
     *     { "term": "Term 3", "start_date": "2026-09-01", "end_date": "2026-11-27" }
     *   ]
     * }
     */
    public function storeBulk(Request $request)
    {
        $user   = Auth::user();
        $school = School::find($user->school_id);

        if (!$school) {
            return response()->json(['message' => 'School not found'], 404);
        }

        // Top-level validation
        $topRules = [
            'year'               => 'required|string|max:255',
            'terms'              => 'required|array|min:1',
            'terms.*.term'       => 'required|string|max:255',
            'terms.*.start_date' => 'nullable|date',
            'terms.*.end_date'   => 'nullable|date|after_or_equal:terms.*.start_date',
            'terms.*.is_active'  => 'sometimes|boolean',
        ];

        if ($school->primary_curriculum === 'Both') {
            $topRules['curriculum_type'] = 'required|in:CBC,8-4-4';
        } else {
            $topRules['curriculum_type'] = 'sometimes|in:CBC,8-4-4';
        }

        $validated = $request->validate($topRules);

        // Resolve curriculum type
        $curriculumType = $school->primary_curriculum !== 'Both'
            ? $school->primary_curriculum
            : $validated['curriculum_type'];

        // Past-year flag — if the year has already passed, force all terms inactive
        $isPastYear = (int) $validated['year'] < (int) date('Y');

        // Prevent duplicate terms for the same year in the same school
        $incomingTermNames = array_column($validated['terms'], 'term');

        $existingTerms = AcademicYear::where('school_id', $user->school_id)
            ->where('year', $validated['year'])
            ->where('curriculum_type', $curriculumType)
            ->whereIn('term', $incomingTermNames)
            ->pluck('term')
            ->toArray();

        if (!empty($existingTerms)) {
            return response()->json([
                'message'        => 'Some terms already exist for this year.',
                'existing_terms' => $existingTerms,
            ], 422);
        }

        // Build records and insert inside a transaction
        $created = DB::transaction(function () use ($validated, $user, $curriculumType, $isPastYear) {
            $records = [];

            foreach ($validated['terms'] as $termData) {
                $records[] = AcademicYear::create([
                    'school_id'       => $user->school_id,
                    'year'            => $validated['year'],
                    'curriculum_type' => $curriculumType,
                    'term'            => $termData['term'],
                    'start_date'      => $termData['start_date'] ?? null,
                    'end_date'        => $termData['end_date']   ?? null,
                    // Force inactive when the year is already in the past
                    'is_active'       => $isPastYear ? false : ($termData['is_active'] ?? false),
                ]);
            }

            return $records;
        });

        return response()->json([
            'message' => count($created) . ' term(s) created successfully.',
            'data'    => $created,
        ], 201);
    }

    /**
     * Get academic years by curriculum.
     */
    public function getByCurriculum($curriculum)
    {
        $user = Auth::user();

        $curriculum = str_replace('_', '-', $curriculum);

        if (!in_array($curriculum, ['CBC', '8-4-4'])) {
            return response()->json([
                'message' => 'Invalid curriculum type',
                'error'   => 'InvalidCurriculumType',
            ], 400);
        }

        return AcademicYear::where('school_id', $user->school_id)
            ->where('curriculum_type', $curriculum)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Show a single academic year.
     */
    public function show($id)
    {
        $user = Auth::user();

        return AcademicYear::where('school_id', $user->school_id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Update an academic year.
     * Prevents re-activating a term that belongs to a past year.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();

        $academicYear = AcademicYear::where('school_id', $user->school_id)
            ->where('id', $id)
            ->firstOrFail();

        $school = School::find($user->school_id);

        if (!$school) {
            return response()->json(['message' => 'School not found'], 404);
        }

        $validationRules = [
            'year'       => 'sometimes|string|max:255',
            'term'       => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date|nullable',
            'end_date'   => 'sometimes|date|nullable',
            'is_active'  => 'sometimes|boolean',
        ];

        if ($school->primary_curriculum === 'Both') {
            $validationRules['curriculum_type'] = 'sometimes|in:CBC,8-4-4';
        } else {
            if ($request->has('curriculum_type') && $request->curriculum_type !== $school->primary_curriculum) {
                return response()->json([
                    'message' => 'Curriculum type cannot be changed for this school',
                    'error'   => 'InvalidCurriculumChange',
                ], 422);
            }
            $validationRules['curriculum_type'] = 'sometimes|in:CBC,8-4-4';
        }

        $validated = $request->validate($validationRules);

        if ($school->primary_curriculum !== 'Both') {
            $validated['curriculum_type'] = $school->primary_curriculum;
        }

        // Determine the effective year (use updated value if provided, otherwise existing)
        $effectiveYear = (int) ($validated['year'] ?? $academicYear->year);

        // Prevent activating terms from past years
        if ($effectiveYear < (int) date('Y')) {
            $validated['is_active'] = false;
        }

        $academicYear->update($validated);

        return $academicYear;
    }

    /**
     * Delete an academic year.
     */
    public function destroy($id)
    {
        $user = Auth::user();

        $academicYear = AcademicYear::where('school_id', $user->school_id)
            ->where('id', $id)
            ->firstOrFail();

        $academicYear->delete();

        return response()->json(['message' => 'Academic year deleted successfully']);
    }
}