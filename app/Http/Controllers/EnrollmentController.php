<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\EnrollmentSetting;
use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Requests\UpdateEnrollmentRequest;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EnrollmentController extends Controller
{
    public function __construct(
        protected EnrollmentService $enrollmentService
    ) {}

    // =========================================================================
    // STATUS CHECK
    // =========================================================================

    /**
     * Check if enrollment is currently open for a school + academic year.
     * Called before showing the application form so the parent knows if they
     * can apply, and which enrollment types are accepted.
     *
     * GET /api/enrollment/status?school_id=1&academic_year_id=2
     */
    public function checkStatus(Request $request): JsonResponse
    {
        $request->validate([
            'school_id'        => ['required', 'integer', 'exists:schools,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ]);

        $settings = EnrollmentSetting::where('school_id', $request->school_id)
            ->where('academic_year_id', $request->academic_year_id)
            ->first();

        if (! $settings) {
            return response()->json([
                'open'    => false,
                'message' => 'Enrollment has not been configured for this school yet.',
            ]);
        }

        return response()->json([
            'open'               => $settings->isAcceptingApplications(),
            'allow_waitlist'     => $settings->allow_waitlist,
            'spots_remaining'    => $settings->spotsRemaining(),
            'accepts_new'        => $settings->accept_new_students,
            'accepts_transfers'  => $settings->accept_transfers,
            'accepts_returning'  => $settings->accept_returning,
            'close_date'         => $settings->close_date?->format('Y-m-d'),
            'required_documents' => $settings->required_documents ?? [],
            'message'            => $settings->isAcceptingApplications()
                ? 'Enrollment is currently open.'
                : 'Enrollment is currently closed.',
        ]);
    }

    // =========================================================================
    // APPLICATION SUBMISSION
    // =========================================================================

    /**
     * Save a new enrollment application as a draft.
     * Returns the tracking_token ONCE — it must be sent to the parent in the
     * confirmation email. It is never returned again after this response.
     *
     * POST /api/enrollment
     */
    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = Enrollment::create(
            array_merge($request->validated(), ['status' => 'draft'])
        );

        return response()->json([
            'message'        => 'Application saved as draft.',
            // tracking_token returned ONCE here — sent to parent in confirmation email.
            // Parent uses it in the status check URL (/api/enrollment/track).
            'tracking_token' => $enrollment->tracking_token,
            'enrollment_id'  => $enrollment->id,
            'enrollment'     => $enrollment->makeHidden(['tracking_token']),
        ], 201);
    }

    /**
     * Submit a draft application (parent clicks the final "Submit" button).
     * This is the step that triggers capacity checks, waitlisting, and
     * sends the confirmation email/SMS to the parent.
     *
     * POST /api/enrollment/{enrollment}/submit
     */
    public function submit(Enrollment $enrollment): JsonResponse
    {
        try {
            $result = $this->enrollmentService->submit($enrollment);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $statusMessages = [
            'submitted'  => 'Your application has been submitted successfully. You will be notified of the outcome.',
            'waitlisted' => 'The school is currently at capacity. Your application has been added to the waitlist.',
            'approved'   => 'Your application has been approved.',
        ];

        return response()->json([
            'message'    => $statusMessages[$result->status] ?? 'Application submitted.',
            'status'     => $result->status,
            'enrollment' => $result->makeHidden(['tracking_token']),
        ]);
    }

    /**
     * Applicant checks the status of their application (authenticated view).
     * Returns full enrollment details including assigned classroom/stream.
     *
     * GET /api/enrollment/{enrollment}
     */
    public function show(Enrollment $enrollment): JsonResponse
    {
        return response()->json([
            'enrollment' => $enrollment->load([
                'school:id,name,code',
                'academicYear:id,year,term',
                'applyingForClassroom:id,class_name',
                'applyingForStream:id,name',
                'assignedClassroom:id,class_name',
                'assignedStream:id,name',
            ]),
        ]);
    }

    /**
     * Update a draft before the final submission (parent edits their form).
     * Only draft applications can be updated.
     *
     * PUT /api/enrollment/{enrollment}
     */
    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        if ($enrollment->status !== 'draft') {
            return response()->json([
                'message' => 'Only draft applications can be edited.',
            ], 422);
        }

        $enrollment->update($request->validated());

        return response()->json([
            'message'    => 'Application updated.',
            'enrollment' => $enrollment->fresh(),
        ]);
    }

    // =========================================================================
    // UNAUTHENTICATED STATUS TRACKING
    // =========================================================================

    /**
     * Unauthenticated application tracking.
     * Parent checks their application status using the reference number + email
     * from their confirmation email. No login required.
     *
     * Designed for the "Track your application" link sent in the confirmation email.
     * Both the enrollment ID and parent email must match — this prevents fishing.
     *
     * Rate limiting: applied at route level (throttle:10,1 — 10 per minute per IP).
     *
     * GET /api/enrollment/track?ref=12&email=parent@example.com
     */
    public function track(Request $request): JsonResponse
    {
        $request->validate([
            'ref'   => ['required', 'integer'],
            'email' => ['required', 'email'],
        ]);

        $enrollment = Enrollment::where('id', $request->ref)
            ->where('parent_email', $request->email)
            ->with([
                'school:id,name,code,phone,email',
                'academicYear:id,year,term',
                'applyingForClassroom:id,class_name',
                'assignedClassroom:id,class_name',
                'assignedStream:id,name',
            ])
            ->first();

        // Return the same response for not-found AND email mismatch
        // — prevents email enumeration attacks
        if (! $enrollment) {
            return response()->json([
                'found'   => false,
                'message' => 'No application found with that reference number and email address. Please check and try again.',
            ], 404);
        }

        // Record the lookup for analytics + rate monitoring
        if (method_exists($enrollment, 'recordStatusCheck')) {
            $enrollment->recordStatusCheck();
        }

        $statusLabels = [
            'draft'        => 'Draft — not yet submitted',
            'submitted'    => 'Submitted — awaiting review',
            'under_review' => 'Under Review — being processed',
            'approved'     => 'Approved',
            'rejected'     => 'Unsuccessful',
            'waitlisted'   => 'Waitlisted — on the waiting list',
        ];

        $response = [
            'found'           => true,
            'reference'       => '#' . str_pad($enrollment->id, 6, '0', STR_PAD_LEFT),
            'status'          => $enrollment->status,
            'status_label'    => $statusLabels[$enrollment->status] ?? $enrollment->status,
            'student_name'    => trim("{$enrollment->first_name} {$enrollment->last_name}"),
            'school'          => [
                'name'  => $enrollment->school->name,
                'phone' => $enrollment->school->phone ?? null,
                'email' => $enrollment->school->email ?? null,
            ],
            'academic_year'   => $enrollment->academicYear->year ?? null,
            'applied_at'      => $enrollment->applied_at?->format('d M Y'),
            'enrollment_type' => ucfirst(str_replace('_', ' ', $enrollment->enrollment_type)),
        ];

        // Add approved-specific fields
        if ($enrollment->status === 'approved') {
            $response['approved_at']     = $enrollment->approved_at?->format('d M Y');
            $response['assigned_class']  = $enrollment->assignedClassroom?->class_name;
            $response['assigned_stream'] = $enrollment->assignedStream?->name;
            $response['message']         = 'Your application has been approved. Please check your email for your admission number and login credentials.';
        }

        // Add rejection reason if rejected
        if ($enrollment->status === 'rejected') {
            $response['rejection_reason'] = $enrollment->rejection_reason;
            $response['rejected_at']      = $enrollment->rejected_at?->format('d M Y');
            $response['message']          = 'Unfortunately your application was not successful. Please contact the school for more information.';
        }

        return response()->json($response);
    }

    // =========================================================================
    // BULK STORE — TEST / SEEDING ONLY
    // =========================================================================
    //
    // This endpoint exists purely to seed large volumes of test data without
    // spending hours doing one-by-one Postman requests.
    //
    // IT IS NOT part of the production enrollment flow.
    // Once testing is complete, comment out this method AND its route in api.php.
    //
    // What it does differently from the normal flow:
    //   - Accepts an array of up to 500 students in a single request
    //   - Bypasses the enrollment-open date window check (test data should not
    //     be blocked by closed enrollment windows)
    //   - Skips per-row email/SMS notifications (would spam 500 messages)
    //   - Wraps everything in a single DB transaction for speed and atomicity
    //   - Validates each row individually and reports exactly which rows failed
    //   - Sets status = submitted directly (skips the separate /submit step)
    //
    // POST /api/enrollment/bulk
    // Body: { "students": [ { ...student fields... }, { ... }, ... ] }
    // =========================================================================
    public function bulkStore(Request $request): JsonResponse
    {
        // ── Top-level validation — must be an array of 1–500 ─────────────────
        $request->validate([
            'students'   => ['required', 'array', 'min:1', 'max:500'],
            'students.*' => ['required', 'array'],
        ]);

        // ── Per-row field rules (mirrors StoreEnrollmentRequest) ──────────────
        $rowRules = [
            'school_id'                  => ['required', 'integer', 'exists:schools,id'],
            'academic_year_id'           => ['required', 'integer', 'exists:academic_years,id'],
            'enrollment_type'            => ['required', 'in:new,transfer,returning,government_placement'],
            'first_name'                 => ['required', 'string', 'max:255'],
            'last_name'                  => ['required', 'string', 'max:255'],
            'middle_name'                => ['nullable', 'string', 'max:255'],
            'date_of_birth'              => ['required', 'date', 'before:today'],
            'gender'                     => ['required', 'in:male,female,other'],
            'nationality'                => ['nullable', 'string', 'max:255'],
            'religion'                   => ['nullable', 'string', 'max:255'],
            'birth_certificate_number'   => ['nullable', 'string', 'max:255'],
            'special_needs'              => ['required', 'boolean'],
            'special_needs_details'      => ['nullable', 'string'],
            'parent_first_name'          => ['required', 'string', 'max:255'],
            'parent_last_name'           => ['required', 'string', 'max:255'],
            'parent_phone'               => ['required', 'string', 'max:20'],
            'parent_email'               => ['nullable', 'email', 'max:255'],
            'parent_national_id'         => ['nullable', 'string', 'max:50'],
            'parent_relationship'        => ['required', 'in:father,mother,guardian,other'],
            'parent_occupation'          => ['nullable', 'string', 'max:255'],
            'parent_address'             => ['nullable', 'string'],
            'applying_for_classroom_id'  => ['nullable', 'integer'],
            'applying_for_stream_id'     => ['nullable', 'integer'],
            'is_transfer'                => ['required', 'boolean'],
            'previous_school_name'       => ['nullable', 'string', 'max:255'],
            'previous_school_address'    => ['nullable', 'string', 'max:255'],
            'previous_admission_number'  => ['nullable', 'string', 'max:100'],
            'leaving_certificate_number' => ['nullable', 'string', 'max:100'],
            'last_class_attended'        => ['nullable', 'string', 'max:100'],
            'documents'                  => ['nullable', 'array'],
        ];

        // ── Validate every row, collect failures ──────────────────────────────
        $failed = [];
        $valid  = [];

        foreach ($request->input('students') as $index => $row) {
            $v = Validator::make($row, $rowRules);

            if ($v->fails()) {
                $failed[] = [
                    'row'    => $index + 1,
                    'name'   => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                    'errors' => $v->errors()->toArray(),
                ];
            } else {
                $valid[] = $v->validated();
            }
        }

        // ── If any row failed, return all errors — do not touch the DB ────────
        if (! empty($failed)) {
            return response()->json([
                'message' => count($failed) . ' row(s) failed validation. No records were inserted. Fix the errors and resubmit.',
                'failed'  => $failed,
                'passed'  => count($valid),
            ], 422);
        }

        // ── Insert all valid rows in a single transaction ─────────────────────
        $inserted = [];
        $now      = now();

        DB::transaction(function () use ($valid, $now, &$inserted) {
            foreach ($valid as $data) {
                // Force status = submitted and set applied_at timestamp.
                // tracking_token is auto-generated by the Enrollment model's booted() hook.
                $enrollment = Enrollment::create(array_merge($data, [
                    'status'     => 'submitted',
                    'applied_at' => $now,
                ]));

                $inserted[] = [
                    'id'     => $enrollment->id,
                    'name'   => trim("{$enrollment->first_name} {$enrollment->last_name}"),
                    'status' => $enrollment->status,
                ];
            }
        });

        return response()->json([
            'message'  => count($inserted) . ' students enrolled and submitted successfully.',
            'inserted' => count($inserted),
            'students' => $inserted,
        ], 201);
    }
}