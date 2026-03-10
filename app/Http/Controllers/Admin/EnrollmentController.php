<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Http\Requests\ApproveEnrollmentRequest;
use App\Http\Requests\RejectEnrollmentRequest;
use App\Http\Requests\UpdateEnrollmentRequest;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function __construct(
        protected EnrollmentService $enrollmentService
    ) {}

    /**
     * List all enrollments for the admin's school with filters.
     *
     * Uses $request->auth_user — set by your AuthenticateWithRedis middleware,
     * exactly the same as StreamController, SchoolController, UserController etc.
     *
     * GET /api/admin/enrollments?status=submitted&academic_year_id=1&search=john
     */
    public function index(Request $request): JsonResponse
    {
        // ── Same auth pattern as your existing controllers ────────────────────
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $schoolId = $user->school_id;
        if (! $schoolId) {
            return response()->json(['message' => 'User is not associated with any school.'], 400);
        }

        $query = Enrollment::forSchool($schoolId)
            ->with([
                'academicYear:id,year,term',
                'applyingForClassroom:id,class_name',
                'applyingForStream:id,name',
                'assignedClassroom:id,class_name',
                'assignedStream:id,name',
                'reviewedBy:id,full_name',
                'approvedBy:id,full_name',
                'student:id,admission_number',
            ]);

        // ── Filters ───────────────────────────────────────────────────────────
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('academic_year_id')) {
            $query->forAcademicYear($request->academic_year_id);
        }

        if ($request->filled('enrollment_type')) {
            $query->where('enrollment_type', $request->enrollment_type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name',    'like', "%{$search}%")
                  ->orWhere('last_name',   'like', "%{$search}%")
                  ->orWhere('parent_phone','like', "%{$search}%")
                  ->orWhere('parent_email','like', "%{$search}%");
            });
        }

        $enrollments = $query->orderBy('applied_at', 'desc')
            ->paginate($request->input('per_page', 20));

        // ── Summary counts for dashboard header ───────────────────────────────
        $counts = Enrollment::forSchool($schoolId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'status'      => 'success',
            'enrollments' => $enrollments,
            'summary'     => [
                'draft'        => $counts['draft']        ?? 0,
                'submitted'    => $counts['submitted']    ?? 0,
                'under_review' => $counts['under_review'] ?? 0,
                'approved'     => $counts['approved']     ?? 0,
                'rejected'     => $counts['rejected']     ?? 0,
                'waitlisted'   => $counts['waitlisted']   ?? 0,
                'total'        => $counts->sum(),
            ],
        ]);
    }

    /**
     * View a single enrollment application in full detail.
     *
     * GET /api/admin/enrollments/{enrollment}
     */
    public function show(Request $request, Enrollment $enrollment): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (! $this->schoolMatches($enrollment, $user->school_id)) {
            return response()->json(['message' => 'Unauthorized access to this enrollment.'], 403);
        }

        return response()->json([
            'status'     => 'success',
            'enrollment' => $enrollment->load([
                'school:id,name,code',
                'academicYear:id,year,term',
                'applyingForClassroom:id,class_name',
                'applyingForStream:id,name',
                'assignedClassroom:id,class_name',
                'assignedStream:id,name',
                'reviewedBy:id,full_name',
                'approvedBy:id,full_name',
                'student:id,admission_number,status',
                'student.user:id,email,status',
            ]),
        ]);
    }

    /**
     * Admin opens the application — moves from submitted to under_review.
     *
     * POST /api/admin/enrollments/{enrollment}/review
     */
    public function startReview(Request $request, Enrollment $enrollment): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (! $this->schoolMatches($enrollment, $user->school_id)) {
            return response()->json(['message' => 'Unauthorized access to this enrollment.'], 403);
        }

        try {
            $result = $this->enrollmentService->startReview($enrollment, $user->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status'     => 'success',
            'message'    => 'Application is now under review.',
            'enrollment' => $result,
        ]);
    }

    /**
     * Admin approves the enrollment.
     * EnrollmentObserver fires after this and handles the full chain:
     *   admission number generation → user creation → student creation
     *
     * POST /api/admin/enrollments/{enrollment}/approve
     */
    public function approve(ApproveEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (! $this->schoolMatches($enrollment, $user->school_id)) {
            return response()->json(['message' => 'Unauthorized access to this enrollment.'], 403);
        }

        // Government placement guard — cannot approve until placement is verified
        if (! $enrollment->canBeApproved()) {
            return response()->json([
                'message' => 'This is a government placement application and cannot be approved until the placement is verified. Please verify the student\'s index number against the official MoE placement list first.',
                'action'  => 'POST /api/admin/enrollments/' . $enrollment->id . '/verify-placement',
                'current_verification_status' => $enrollment->placement_verification_status,
            ], 422);
        }

        try {
            $result = $this->enrollmentService->approve(
                $enrollment,
                $user->id,
                $request->input('assigned_classroom_id'),
                $request->input('assigned_stream_id')
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Reload with the student record the observer just created
        $result->load([
            'student:id,admission_number',
            'assignedClassroom:id,class_name',
            'assignedStream:id,name',
        ]);

        return response()->json([
            'status'           => 'success',
            'message'          => 'Enrollment approved. Student record and admission number have been created.',
            'enrollment'       => $result,
            'admission_number' => $result->student?->admission_number,
            'student_id'       => $result->student_id,
        ]);
    }

    /**
     * Admin rejects the enrollment with a reason.
     *
     * POST /api/admin/enrollments/{enrollment}/reject
     */
    public function reject(RejectEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (! $this->schoolMatches($enrollment, $user->school_id)) {
            return response()->json(['message' => 'Unauthorized access to this enrollment.'], 403);
        }

        try {
            $result = $this->enrollmentService->reject(
                $enrollment,
                $user->id,
                $request->input('rejection_reason')
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status'     => 'success',
            'message'    => 'Enrollment rejected.',
            'enrollment' => $result,
        ]);
    }

    /**
     * Admin updates notes or assignment details during review.
     *
     * PUT /api/admin/enrollments/{enrollment}
     */
    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        if (! $this->schoolMatches($enrollment, $user->school_id)) {
            return response()->json(['message' => 'Unauthorized access to this enrollment.'], 403);
        }

        $enrollment->update($request->validated());

        return response()->json([
            'status'     => 'success',
            'message'    => 'Enrollment updated.',
            'enrollment' => $enrollment->fresh(),
        ]);
    }

    /**
     * View the waitlist for a specific academic year.
     *
     * GET /api/admin/enrollments/waitlist?academic_year_id=1
     */
    public function waitlist(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $waitlist = Enrollment::forSchool($user->school_id)
            ->forAcademicYear($request->academic_year_id)
            ->waitlisted()
            ->orderBy('applied_at') // FIFO — oldest application first
            ->get();

        return response()->json([
            'status'    => 'success',
            'waitlist'  => $waitlist,
            'count'     => $waitlist->count(),
        ]);
    }

    /**
     * Promote the oldest waitlisted application when a spot opens.
     *
     * POST /api/admin/enrollments/waitlist/promote
     */
    public function promoteFromWaitlist(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please log in.'], 401);
        }

        $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $promoted = $this->enrollmentService->promoteFromWaitlist(
            $user->school_id,
            $request->academic_year_id
        );

        if (! $promoted) {
            return response()->json([
                'status'  => 'success',
                'message' => 'No applications on the waitlist.',
            ], 404);
        }

        return response()->json([
            'status'     => 'success',
            'message'    => 'Application promoted from waitlist to submitted.',
            'enrollment' => $promoted,
        ]);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    /**
     * Ensure the enrollment belongs to the admin's school.
     * Same school-scoping pattern used throughout your existing controllers.
     */
    private function schoolMatches(Enrollment $enrollment, ?int $schoolId): bool
    {
        return $enrollment->school_id === $schoolId;
    }

    // ── Government Placement Verification ────────────────────────────────────

    /**
     * List all government placement applications needing verification.
     * Admin sees these as a separate queue from regular enrollments.
     *
     * GET /api/admin/enrollments/placements
     */
    public function placements(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $query = Enrollment::forSchool($user->school_id)
            ->where('enrollment_type', 'government_placement')
            ->with([
                'academicYear:id,year,term',
                'applyingForClassroom:id,class_name',
            ]);

        // Filter by placement verification status
        if ($request->filled('verification_status')) {
            $query->where('placement_verification_status', $request->verification_status);
        }

        // Filter by placement year
        if ($request->filled('placement_year')) {
            $query->where('placement_year', $request->placement_year);
        }

        $placements = $query->orderBy('applied_at', 'asc')->paginate(25);

        // Summary counts for the admin dashboard badge
        $summary = [
            'pending'  => Enrollment::forSchool($user->school_id)
                ->where('enrollment_type', 'government_placement')
                ->where('placement_verification_status', 'pending')->count(),
            'verified' => Enrollment::forSchool($user->school_id)
                ->where('enrollment_type', 'government_placement')
                ->where('placement_verification_status', 'verified')->count(),
            'disputed' => Enrollment::forSchool($user->school_id)
                ->where('enrollment_type', 'government_placement')
                ->where('placement_verification_status', 'disputed')->count(),
            'manual'   => Enrollment::forSchool($user->school_id)
                ->where('enrollment_type', 'government_placement')
                ->where('placement_verification_status', 'manual')->count(),
        ];

        return response()->json([
            'placements' => $placements,
            'summary'    => $summary,
        ]);
    }

    /**
     * Verify a government placement — admin confirms the student
     * is on the official MoE/KNEC placement list for this school.
     *
     * POST /api/admin/enrollments/{enrollment}/verify-placement
     *
     * Verification flow:
     *   1. School receives official placement list from Ministry
     *   2. Admin cross-checks each application's index number against the list
     *   3. If found → verify (enrollment can now proceed to approval)
     *   4. If not found → dispute (enrollment blocked, parent asked to visit school)
     *   5. If letter uploaded only → manual (needs physical verification)
     */
    public function verifyPlacement(Request $request, Enrollment $enrollment): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if (! $this->schoolMatches($enrollment, $user->school_id)) {
            return response()->json(['message' => 'Enrollment not found.'], 404);
        }

        if ($enrollment->enrollment_type !== 'government_placement') {
            return response()->json([
                'message' => 'This enrollment is not a government placement. Only government placement applications require placement verification.',
            ], 422);
        }

        $request->validate([
            'verification_status' => ['required', 'in:verified,disputed,manual'],
            'notes'               => ['nullable', 'string', 'max:1000'],
        ]);

        $enrollment->update([
            'placement_verification_status' => $request->verification_status,
            'placement_verification_notes'  => $request->notes,
            'placement_verified_by'         => $user->id,
            'placement_verified_at'         => now(),
        ]);

        // If verified and enrollment is still in submitted/under_review,
        // it is now unblocked and can be approved
        $messages = [
            'verified' => 'Placement verified. This application can now be approved.',
            'disputed' => 'Placement marked as disputed. Parent has been flagged to visit school with original documents.',
            'manual'   => 'Placement marked for manual review. Original placement letter needs physical verification.',
        ];

        return response()->json([
            'message'    => $messages[$request->verification_status],
            'enrollment' => $enrollment->fresh(),
        ]);
    }

    /**
     * Bulk verify placements — admin uploads/pastes the official
     * placement list and the system auto-matches against pending applications.
     *
     * POST /api/admin/enrollments/placements/bulk-verify
     *
     * Admin pastes the index numbers from the official Ministry list.
     * System matches them against pending government_placement enrollments.
     * Matched ones → verified. Unmatched remaining → stay pending.
     */
    public function bulkVerifyPlacements(Request $request): JsonResponse
    {
        $user = $request->auth_user;
        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $request->validate([
            // Array of index numbers from the official MoE placement list
            'index_numbers'   => ['required', 'array', 'min:1'],
            'index_numbers.*' => ['required', 'string'],
            'placement_year'  => ['required', 'integer'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $officialList    = collect($request->index_numbers)->map(fn($n) => trim(strtoupper($n)));
        $verifiedCount   = 0;
        $notFoundCount   = 0;
        $notFoundNumbers = [];

        // Find all pending government placement enrollments for this school + year
        $pending = Enrollment::forSchool($user->school_id)
            ->where('enrollment_type', 'government_placement')
            ->where('placement_year', $request->placement_year)
            ->where('placement_verification_status', 'pending')
            ->whereNotNull('assessment_index_number')
            ->get();

        foreach ($pending as $enrollment) {
            $indexNumber = strtoupper(trim($enrollment->assessment_index_number));

            if ($officialList->contains($indexNumber)) {
                $enrollment->update([
                    'placement_verification_status' => 'verified',
                    'placement_verification_notes'  => $request->notes ?? 'Verified via bulk upload against official MoE placement list.',
                    'placement_verified_by'         => $user->id,
                    'placement_verified_at'         => now(),
                ]);
                $verifiedCount++;
            }
        }

        // Report which submitted index numbers had NO matching application
        foreach ($officialList as $indexNumber) {
            $exists = Enrollment::forSchool($user->school_id)
                ->where('assessment_index_number', $indexNumber)
                ->exists();
            if (! $exists) {
                $notFoundNumbers[] = $indexNumber;
                $notFoundCount++;
            }
        }

        return response()->json([
            'message'          => "Bulk verification complete. {$verifiedCount} applications verified.",
            'verified_count'   => $verifiedCount,
            'not_found_in_system' => [
                'count'   => $notFoundCount,
                'numbers' => $notFoundNumbers,
                'note'    => 'These index numbers are on the official list but no matching application was found in the system. Students may not have applied yet.',
            ],
        ]);
    }
}