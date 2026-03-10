<?php

namespace App\Services;

use App\Mail\EnrollmentSubmittedMail;
use App\Mail\EnrollmentApprovedMail;
use App\Mail\EnrollmentRejectedMail;
use App\Mail\EnrollmentWaitlistedMail;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        protected SmsService $smsService
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // ENROLLMENT SUBMITTED
    // Fires when parent clicks Submit on the application form
    // ─────────────────────────────────────────────────────────────────────────

    public function enrollmentSubmitted(Enrollment $enrollment): void
    {
        if (! $this->shouldNotify($enrollment, 'notify_parent_on_submit')) {
            return;
        }

        // Email
        $this->sendEmail(
            to: $enrollment->parent_email,
            mailable: new EnrollmentSubmittedMail($enrollment),
            context: "submitted #{$enrollment->id}"
        );

        // SMS — short, actionable message
        $ref = '#' . str_pad($enrollment->id, 6, '0', STR_PAD_LEFT);
        $this->sendSms(
            phone: $enrollment->parent_phone,
            message: "Dear {$enrollment->parent_first_name}, your enrollment application for {$enrollment->full_name} at {$enrollment->school->name} has been received. Reference: {$ref}. We will contact you with the outcome shortly.",
            context: "submitted #{$enrollment->id}"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENROLLMENT APPROVED
    // Fires after the observer creates the student + user records
    // This is the most important notification — carries admission number + login
    // ─────────────────────────────────────────────────────────────────────────

    public function enrollmentApproved(
        Enrollment $enrollment,
        Student    $student,
        string     $loginEmail,
        string     $tempPassword
    ): void {
        if (! $this->shouldNotify($enrollment, 'notify_parent_on_approval')) {
            return;
        }

        // Email — full details: admission number + login credentials
        $this->sendEmail(
            to: $enrollment->parent_email,
            mailable: new EnrollmentApprovedMail($enrollment, $student, $loginEmail, $tempPassword),
            context: "approved #{$enrollment->id}"
        );

        // SMS — brief confirmation, full details in email
        $admissionPart = $student->admission_number
            ? " Admission No: {$student->admission_number}."
            : '';

        $classPart = $enrollment->assignedClassroom?->class_name
            ? " Class: {$enrollment->assignedClassroom->class_name}."
            : '';

        $this->sendSms(
            phone: $enrollment->parent_phone,
            message: "APPROVED! Dear {$enrollment->parent_first_name}, {$enrollment->full_name}'s enrollment at {$enrollment->school->name} is approved.{$admissionPart}{$classPart} Login details sent to your email: {$loginEmail}",
            context: "approved #{$enrollment->id}"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENROLLMENT REJECTED
    // ─────────────────────────────────────────────────────────────────────────

    public function enrollmentRejected(Enrollment $enrollment): void
    {
        if (! $this->shouldNotify($enrollment, 'notify_parent_on_rejection')) {
            return;
        }

        // Email — includes the rejection reason
        $this->sendEmail(
            to: $enrollment->parent_email,
            mailable: new EnrollmentRejectedMail($enrollment),
            context: "rejected #{$enrollment->id}"
        );

        // SMS — brief, directs parent to email for the reason
        $ref = '#' . str_pad($enrollment->id, 6, '0', STR_PAD_LEFT);
        $this->sendSms(
            phone: $enrollment->parent_phone,
            message: "Dear {$enrollment->parent_first_name}, we regret to inform you that the enrollment application for {$enrollment->full_name} at {$enrollment->school->name} (Ref: {$ref}) was not approved. Please check your email for the full details.",
            context: "rejected #{$enrollment->id}"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ENROLLMENT WAITLISTED
    // School is at capacity — application held in queue
    // ─────────────────────────────────────────────────────────────────────────

    public function enrollmentWaitlisted(Enrollment $enrollment): void
    {
        // Email
        $this->sendEmail(
            to: $enrollment->parent_email,
            mailable: new EnrollmentWaitlistedMail($enrollment),
            context: "waitlisted #{$enrollment->id}"
        );

        // SMS
        $ref = '#' . str_pad($enrollment->id, 6, '0', STR_PAD_LEFT);
        $this->sendSms(
            phone: $enrollment->parent_phone,
            message: "Dear {$enrollment->parent_first_name}, {$enrollment->full_name}'s application at {$enrollment->school->name} has been waitlisted (Ref: {$ref}). The school is currently at capacity. You will be notified if a place becomes available.",
            context: "waitlisted #{$enrollment->id}"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if notifications are enabled for this school + event.
     * Falls back to the global config default if no school setting exists.
     */
    private function shouldNotify(Enrollment $enrollment, string $settingKey): bool
    {
        $settings = $enrollment->school
            ->enrollmentSettings()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->first();

        if ($settings && isset($settings->$settingKey)) {
            return (bool) $settings->$settingKey;
        }

        // Fall back to global config default
        $configMap = [
            'notify_parent_on_submit'    => 'defaults.enrollment.notify_on_submit',
            'notify_parent_on_approval'  => 'defaults.enrollment.notify_on_approval',
            'notify_parent_on_rejection' => 'defaults.enrollment.notify_on_rejection',
        ];

        $configKey = $configMap[$settingKey] ?? null;
        return $configKey ? config("notifications.{$configKey}", true) : true;
    }

    /**
     * Send email safely — never throws, logs failures.
     * Skips silently if no email address provided.
     */
    private function sendEmail(?string $to, mixed $mailable, string $context): void
    {
        if (! config('notifications.channels.email', true)) {
            Log::info("Email channel disabled. Skipping: {$context}");
            return;
        }

        if (empty($to)) {
            Log::info("No email address — skipping email for: {$context}");
            return;
        }

        try {
            Mail::to($to)->send($mailable);
            Log::info("Email sent to {$to} for: {$context}");
        } catch (\Exception $e) {
            // A failed email must never roll back a student creation
            Log::error("Email failed for {$context}: " . $e->getMessage());
        }
    }

    /**
     * Send SMS safely — formats Kenyan number, logs, never throws.
     */
    private function sendSms(string $phone, string $message, string $context): void
    {
        if (! config('notifications.channels.sms', true)) {
            Log::info("SMS channel disabled. Skipping: {$context}");
            return;
        }

        $formatted = $this->smsService->formatKenyanNumber($phone);
        $this->smsService->send($formatted, $message);
    }
}