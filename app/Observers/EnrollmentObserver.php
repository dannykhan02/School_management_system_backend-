<?php

namespace App\Observers;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use App\Models\Role;
use App\Models\EnrollmentSetting;
use App\Services\AdmissionNumberService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EnrollmentObserver
{
    public function __construct(
        protected AdmissionNumberService $admissionNumberService,
        protected NotificationService    $notificationService,
    ) {}

    public function updated(Enrollment $enrollment): void
    {
        if (! $enrollment->wasChanged('status')) {
            return;
        }

        match ($enrollment->status) {
            'approved'   => $this->handleApproval($enrollment),
            'rejected'   => $this->handleRejection($enrollment),
            'waitlisted' => $this->handleWaitlisted($enrollment),
            'submitted'  => $this->handleSubmitted($enrollment),
            default      => null,
        };
    }

    // ── Approval ──────────────────────────────────────────────────────────────

    private function handleApproval(Enrollment $enrollment): void
    {
        $student      = null;
        $loginEmail   = null;
        $tempPassword = null;

        DB::transaction(function () use ($enrollment, &$student, &$loginEmail, &$tempPassword) {

            $settings = EnrollmentSetting::where('school_id', $enrollment->school_id)
                ->where('academic_year_id', $enrollment->academic_year_id)
                ->lockForUpdate()
                ->first();

            // Final capacity safety net
            if ($settings && $settings->max_capacity > 0) {
                if ($settings->current_enrolled >= $settings->max_capacity) {
                    $enrollment->updateQuietly(['status' => 'waitlisted']);
                    return;
                }
            }

            // Generate admission number
            $admissionNumber = $this->admissionNumberService->generate(
                $enrollment->school_id,
                $enrollment->academicYear
            );

            // Create user account
            $studentRole  = Role::where('name', 'student')->first();
            $tempPassword = Str::random(10);
            $loginEmail   = $this->resolveEmail($enrollment);

            $user = User::create([
                'school_id'            => $enrollment->school_id,
                'role_id'              => $studentRole?->id,
                'full_name'            => $enrollment->full_name,
                'email'                => $loginEmail,
                'phone'                => $enrollment->parent_phone,
                'password'             => Hash::make($tempPassword),
                'gender'               => $enrollment->gender,
                'status'               => 'active',
                'must_change_password' => true,
            ]);

            $classroomId = $enrollment->assigned_classroom_id ?? $enrollment->applying_for_classroom_id;
            $streamId    = $enrollment->assigned_stream_id    ?? $enrollment->applying_for_stream_id;

            $student = Student::create([
                'user_id'                    => $user->id,
                'school_id'                  => $enrollment->school_id,
                'class_id'                   => $classroomId,
                'stream_id'                  => $streamId,
                'status'                     => 'active',
                'admission_number'           => $admissionNumber,
                'admission_number_is_manual' => false,
                'admitted_academic_year_id'  => $enrollment->academic_year_id,
                'date_of_birth'              => $enrollment->date_of_birth,
                'gender'                     => $enrollment->gender,
                'admission_date'             => now()->toDateString(),
            ]);

            $enrollment->updateQuietly([
                'student_id'            => $student->id,
                'user_id'               => $user->id,
                'assigned_classroom_id' => $classroomId,
                'assigned_stream_id'    => $streamId,
            ]);

            $settings?->incrementEnrolled();
        });

        // Notifications fire OUTSIDE the transaction
        // A failed email/SMS must never roll back a student creation
        if ($student && $loginEmail && $tempPassword) {
            $this->notificationService->enrollmentApproved(
                $enrollment->fresh()->load(['school', 'academicYear', 'assignedClassroom', 'assignedStream']),
                $student,
                $loginEmail,
                $tempPassword
            );
        }
    }

    private function handleSubmitted(Enrollment $enrollment): void
    {
        $this->notificationService->enrollmentSubmitted(
            $enrollment->load(['school', 'academicYear'])
        );
    }

    private function handleRejection(Enrollment $enrollment): void
    {
        $this->notificationService->enrollmentRejected(
            $enrollment->load(['school', 'academicYear'])
        );
    }

    private function handleWaitlisted(Enrollment $enrollment): void
    {
        $this->notificationService->enrollmentWaitlisted(
            $enrollment->load(['school', 'academicYear'])
        );
    }

    private function resolveEmail(Enrollment $enrollment): string
    {
        if ($enrollment->parent_email) {
            $exists = User::where('email', $enrollment->parent_email)
                ->where('school_id', $enrollment->school_id)
                ->exists();
            if (! $exists) {
                return $enrollment->parent_email;
            }
        }

        $schoolCode = strtolower($enrollment->school->code ?? 'sch');
        $base       = strtolower(Str::slug("{$enrollment->first_name}.{$enrollment->last_name}"));
        $email      = "{$base}@{$schoolCode}.school";
        $counter    = 1;
        $attempt    = $email;

        while (User::where('email', $attempt)->exists()) {
            $attempt = "{$base}{$counter}@{$schoolCode}.school";
            $counter++;
        }

        return $attempt;
    }
}