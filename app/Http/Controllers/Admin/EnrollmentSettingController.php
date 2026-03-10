<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\EnrollmentSetting;
use App\Http\Requests\StoreEnrollmentSettingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentSettingController extends Controller
{
    /**
     * List every enrollment setting for this school, grouped by calendar year.
     *
     * Because each academic_year row represents a single term (e.g. "2026 Term 1"),
     * the response is keyed by calendar year so the frontend can render a
     * year → [Term 1, Term 2, Term 3] tree without any extra processing.
     *
     * GET /api/admin/enrollment-settings
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $settings = EnrollmentSetting::with('academicYear')
            ->where('school_id', $user->school_id)
            ->get()
            ->map(fn (EnrollmentSetting $s) => [
                ...$s->toArray(),
                'is_open'         => $s->isAcceptingApplications(),
                'spots_remaining' => $s->spotsRemaining(),
                'at_capacity'     => $s->isAtCapacity(),
                'term_label'      => $s->academicYear?->term,  // "Term 1"
                'year'            => $s->academicYear?->year,  // 2026
            ])
            ->groupBy('year'); // keyed by calendar year, e.g. { "2026": [...], "2024": [...] }

        return response()->json([
            'status'            => 'success',
            'settings_by_year'  => $settings,
        ]);
    }

    /**
     * Get enrollment settings for a specific term (academic year row).
     *
     * GET /api/admin/enrollment-settings?academic_year_id=10
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $settings = EnrollmentSetting::with('academicYear')
            ->where('school_id', $user->school_id)
            ->where('academic_year_id', $request->academic_year_id)
            ->first();

        if (! $settings) {
            return response()->json([
                'status'   => 'success',
                'settings' => null,
                'message'  => 'No enrollment settings found for this term. Please configure them.',
            ]);
        }

        return response()->json([
            'status'          => 'success',
            'settings'        => $settings,
            'term_label'      => $settings->termLabel(),        // "2026 – Term 1"
            'is_open'         => $settings->isAcceptingApplications(),
            'spots_remaining' => $settings->spotsRemaining(),
            'at_capacity'     => $settings->isAtCapacity(),
        ]);
    }

    /**
     * Create or fully replace enrollment settings for a school + term pair.
     *
     * Each academic_year row is a single term, so academic_year_id already
     * gives us term-level granularity. The uniqueness constraint on that
     * column (scoped to school_id) is enforced both in the FormRequest and
     * at the DB level via a migration.
     *
     * school_id is taken exclusively from the authenticated user — the client
     * must never supply it (see StoreEnrollmentSettingRequest for the full
     * explanation of why).
     *
     * POST /api/admin/enrollment-settings
     */
    public function store(StoreEnrollmentSettingRequest $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $settings = EnrollmentSetting::updateOrCreate(
            [
                'school_id'        => $user->school_id,
                'academic_year_id' => $request->input('academic_year_id'),
            ],
            // school_id is merged here, not taken from validated() — it never
            // existed in the validated payload in the first place.
            array_merge($request->validated(), ['school_id' => $user->school_id])
        );

        $fresh = $settings->fresh(['academicYear']);

        return response()->json([
            'status'          => 'success',
            'message'         => 'Enrollment settings saved.',
            'settings'        => $fresh,
            'term_label'      => $fresh->termLabel(),
            'is_open'         => $fresh->isAcceptingApplications(),
            'spots_remaining' => $fresh->spotsRemaining(),
        ]);
    }

    /**
     * Partially update an existing EnrollmentSetting record by its primary key.
     *
     * Only the fields present in the request body are changed — this is true
     * partial-update (PATCH) semantics even when called via PUT, because every
     * field in the inline validation uses 'sometimes'. The frontend can send
     * just the one or two fields it changed without re-posting the full payload.
     *
     * The record is scoped to the authenticated admin's school so one school
     * can never accidentally overwrite another school's settings.
     *
     * PUT   /api/admin/enrollment-settings/{id}
     * PATCH /api/admin/enrollment-settings/{id}
     *
     * @param  int  $id  Primary key of the EnrollmentSetting row to update.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $settings = EnrollmentSetting::where('id', $id)
            ->where('school_id', $user->school_id)
            ->first();

        if (! $settings) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Enrollment settings not found for your school.',
            ], 404);
        }

        // 'sometimes' means a rule is only evaluated when the key exists in the
        // input — absent keys are silently ignored, so partial payloads work.
        $validated = $request->validate([
            // ── Enrollment window ─────────────────────────────────────────
            'enrollment_open'  => ['sometimes', 'boolean'],
            'open_date'        => ['sometimes', 'nullable', 'date'],
            'close_date'       => ['sometimes', 'nullable', 'date', 'after_or_equal:open_date'],

            // ── Capacity ──────────────────────────────────────────────────
            'max_capacity'     => ['sometimes', 'integer', 'min:0'],
            'current_enrolled' => ['sometimes', 'integer', 'min:0'],
            'allow_waitlist'   => ['sometimes', 'boolean'],

            // ── Approval ──────────────────────────────────────────────────
            'auto_approve'         => ['sometimes', 'boolean'],
            'required_documents'   => ['sometimes', 'nullable', 'array'],
            'required_documents.*' => ['string'],

            // ── Accepted enrollment types ─────────────────────────────────
            'accept_new_students' => ['sometimes', 'boolean'],
            'accept_transfers'    => ['sometimes', 'boolean'],
            'accept_returning'    => ['sometimes', 'boolean'],

            // ── Notifications ─────────────────────────────────────────────
            'notify_parent_on_submit'         => ['sometimes', 'boolean'],
            'notify_parent_on_approval'       => ['sometimes', 'boolean'],
            'notify_parent_on_rejection'      => ['sometimes', 'boolean'],
            'notify_admin_on_new_application' => ['sometimes', 'boolean'],
        ]);

        $settings->update($validated);

        $fresh = $settings->fresh(['academicYear']);

        return response()->json([
            'status'          => 'success',
            'message'         => 'Enrollment settings updated.',
            'settings'        => $fresh,
            'term_label'      => $fresh->termLabel(),
            'is_open'         => $fresh->isAcceptingApplications(),
            'spots_remaining' => $fresh->spotsRemaining(),
            'at_capacity'     => $fresh->isAtCapacity(),
        ]);
    }

    /**
     * Toggle enrollment open/closed instantly for a given term.
     * Useful for a quick dashboard switch without changing other settings.
     *
     * POST /api/admin/enrollment-settings/toggle
     */
    public function toggle(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $settings = EnrollmentSetting::where('school_id', $user->school_id)
            ->where('academic_year_id', $request->academic_year_id)
            ->firstOrFail();

        $settings->update(['enrollment_open' => ! $settings->enrollment_open]);

        $fresh = $settings->fresh(['academicYear']);
        $state = $fresh->enrollment_open ? 'opened' : 'closed';

        return response()->json([
            'status'          => 'success',
            'message'         => "Enrollment for {$fresh->termLabel()} has been {$state}.",
            'enrollment_open' => $fresh->enrollment_open,
            'term_label'      => $fresh->termLabel(),
        ]);
    }

    /**
     * Dashboard summary for a specific term — capacity progress, status
     * breakdown, and approved-enrollment breakdown by type.
     *
     * academic_year_id here maps to a single term (e.g. "2026 Term 1"),
     * so all counts are naturally scoped to that term.
     *
     * GET /api/admin/enrollment-settings/summary?academic_year_id=10
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $schoolId = $user->school_id;

        $settings = EnrollmentSetting::with('academicYear')
            ->where('school_id', $schoolId)
            ->where('academic_year_id', $request->academic_year_id)
            ->first();

        $counts = Enrollment::forSchool($schoolId)
            ->forAcademicYear($request->academic_year_id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $typeCounts = Enrollment::forSchool($schoolId)
            ->forAcademicYear($request->academic_year_id)
            ->approved()
            ->selectRaw('enrollment_type, COUNT(*) as count')
            ->groupBy('enrollment_type')
            ->pluck('count', 'enrollment_type');

        return response()->json([
            'status'     => 'success',

            // Which term this summary belongs to
            'term' => [
                'label'      => $settings?->academicYear?->term,             // "Term 1"
                'year'       => $settings?->academicYear?->year,             // 2026
                'term_label' => $settings?->termLabel() ?? '—',              // "2026 – Term 1"
                'start_date' => $settings?->academicYear?->start_date,
                'end_date'   => $settings?->academicYear?->end_date,
            ],

            'capacity' => [
                'max'       => $settings?->max_capacity ?? 0,
                'enrolled'  => $settings?->current_enrolled ?? 0,
                'remaining' => $settings?->spotsRemaining(),
                'unlimited' => ($settings?->max_capacity ?? 0) === 0,
            ],

            'status_counts' => [
                'submitted'    => $counts['submitted']    ?? 0,
                'under_review' => $counts['under_review'] ?? 0,
                'approved'     => $counts['approved']     ?? 0,
                'rejected'     => $counts['rejected']     ?? 0,
                'waitlisted'   => $counts['waitlisted']   ?? 0,
            ],

            'approved_by_type' => [
                'new'       => $typeCounts['new']       ?? 0,
                'transfer'  => $typeCounts['transfer']  ?? 0,
                'returning' => $typeCounts['returning'] ?? 0,
            ],
        ]);
    }
}