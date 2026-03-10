<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\EnrollmentSetting;
use RuntimeException;

class EnrollmentService
{
    /**
     * Submit a draft enrollment application.
     *
     * Validates in order:
     *   1. Enrollment window is open
     *   2. This enrollment type is accepted this year
     *   3. All required documents are uploaded
     *   4. School is not at capacity (or waitlist if allowed)
     *
     * @throws RuntimeException with user-friendly message on any failure
     */
    public function submit(Enrollment $enrollment): Enrollment
    {
        if ($enrollment->status !== 'draft') {
            throw new RuntimeException('Only draft applications can be submitted.');
        }

        $settings = $this->getSettings($enrollment);

        // ── Guard 1: enrollment window ────────────────────────────────────────
        if ($settings && ! $settings->isAcceptingApplications()) {
            throw new RuntimeException(
                'Enrollment is not currently open for this school. ' .
                'Please contact the school administration.'
            );
        }

        // ── Guard 2: enrollment type allowed ──────────────────────────────────
        // Uses DB boolean columns: accept_new_students, accept_transfers, accept_returning
        if ($settings && ! $settings->allowsEnrollmentType($enrollment->enrollment_type)) {
            $type = ucfirst($enrollment->enrollment_type);
            throw new RuntimeException(
                "{$type} enrollments are not being accepted by this school at this time."
            );
        }

        // ── Guard 3: required documents ───────────────────────────────────────
        if ($settings) {
            $this->validateDocuments($enrollment, $settings);
        }

        // ── Guard 4: capacity ─────────────────────────────────────────────────
        if ($settings && $settings->isAtCapacity()) {
            if ($settings->allow_waitlist) {
                $enrollment->markAsWaitlisted();
                return $enrollment->fresh();
            }

            throw new RuntimeException(
                'This school has reached its maximum enrollment capacity for this academic year.'
            );
        }

        // ── Submit ────────────────────────────────────────────────────────────
        $enrollment->markAsSubmitted();

        // ── Auto-approve if configured ────────────────────────────────────────
        // For schools that want zero manual review (small schools, simple setups)
        if ($settings && $settings->auto_approve) {
            // Use 0 as system approver ID — adjust to a real system user ID if you have one
            $enrollment->markAsApproved(approverId: 0);
            // EnrollmentObserver fires here automatically
        }

        return $enrollment->fresh();
    }

    /**
     * Admin approves an enrollment.
     *
     * The heavy lifting (number generation, user creation, student creation)
     * is handled automatically by EnrollmentObserver after markAsApproved() fires.
     *
     * @param  Enrollment  $enrollment
     * @param  int  $approverId        The admin's user ID
     * @param  int|null  $classroomId  Final classroom (overrides applicant preference)
     * @param  int|null  $streamId     Final stream (overrides applicant preference)
     */
    public function approve(
        Enrollment $enrollment,
        int $approverId,
        ?int $classroomId = null,
        ?int $streamId = null
    ): Enrollment {
        $allowedStatuses = ['submitted', 'under_review', 'waitlisted'];

        if (! in_array($enrollment->status, $allowedStatuses)) {
            throw new RuntimeException(
                "Cannot approve an enrollment with status '{$enrollment->status}'. " .
                'Only submitted, under_review, or waitlisted applications can be approved.'
            );
        }

        // Set the admin's final classroom/stream assignment BEFORE triggering the observer
        // so the observer reads the correct values when creating the student record
        if ($classroomId) {
            $enrollment->assigned_classroom_id = $classroomId;
        }
        if ($streamId) {
            $enrollment->assigned_stream_id = $streamId;
        }

        // Save the classroom/stream assignment silently first
        // then trigger the status change that fires the observer
        $enrollment->saveQuietly();
        $enrollment->markAsApproved($approverId);

        return $enrollment->fresh();
    }

    /**
     * Admin rejects an enrollment.
     * Rejection reason is mandatory.
     */
    public function reject(
        Enrollment $enrollment,
        int $reviewerId,
        string $reason
    ): Enrollment {
        $allowedStatuses = ['submitted', 'under_review', 'waitlisted'];

        if (! in_array($enrollment->status, $allowedStatuses)) {
            throw new RuntimeException(
                "Cannot reject an enrollment with status '{$enrollment->status}'."
            );
        }

        if (empty(trim($reason))) {
            throw new RuntimeException('A rejection reason is required.');
        }

        $enrollment->markAsRejected($reviewerId, $reason);
        return $enrollment->fresh();
    }

    /**
     * Admin begins reviewing an application.
     * Moves it from submitted → under_review.
     */
    public function startReview(Enrollment $enrollment, int $reviewerId): Enrollment
    {
        if ($enrollment->status !== 'submitted') {
            throw new RuntimeException(
                "Only submitted applications can be moved to under_review."
            );
        }

        $enrollment->markAsUnderReview($reviewerId);
        return $enrollment->fresh();
    }

    /**
     * Promote the oldest waitlisted application when a spot opens.
     * Called when: a student leaves, capacity is increased, or admin manually triggers it.
     */
    public function promoteFromWaitlist(int $schoolId, int $academicYearId): ?Enrollment
    {
        $next = Enrollment::forSchool($schoolId)
            ->forAcademicYear($academicYearId)
            ->waitlisted()
            ->orderBy('applied_at')  // oldest waitlisted application first
            ->first();

        if (! $next) {
            return null; // nobody on waitlist
        }

        $next->update(['status' => 'submitted']);
        return $next;
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function getSettings(Enrollment $enrollment): ?EnrollmentSetting
    {
        return EnrollmentSetting::where('school_id', $enrollment->school_id)
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->first();
    }

    /**
     * Validate that all required documents have been uploaded.
     * required_documents is a JSON array of document slugs on enrollment_settings.
     * documents on the enrollment is a JSON array/object of uploaded file paths.
     */
    private function validateDocuments(Enrollment $enrollment, EnrollmentSetting $settings): void
    {
        $required  = $settings->required_documents ?? [];
        $submitted = array_keys($enrollment->documents ?? []);
        $missing   = array_diff($required, $submitted);

        if (! empty($missing)) {
            $readable = implode(', ', array_map(
                fn($doc) => ucwords(str_replace('_', ' ', $doc)),
                $missing
            ));

            throw new RuntimeException(
                "The following required documents have not been uploaded: {$readable}."
            );
        }
    }
}