<?php

namespace App\Services;

use App\Models\AdmissionConfig;
use App\Models\AcademicYear;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdmissionNumberService
{
    /**
     * Generate and return the next admission number for a school.
     *
     * This method is concurrency-safe: it uses a DB transaction with a
     * row-level lock (SELECT FOR UPDATE) so two simultaneous approvals
     * cannot produce the same number.
     *
     * @param  int  $schoolId
     * @param  AcademicYear|null  $academicYear  Required when reset_yearly = true
     * @return string|null  null when the school has admission numbers disabled
     *
     * @throws RuntimeException  If config is missing or sequence overflows
     */
    public function generate(int $schoolId, ?AcademicYear $academicYear = null): ?string
    {
        return DB::transaction(function () use ($schoolId, $academicYear) {

            // ── 1. Fetch config with exclusive row lock ──────────────────────────
            /** @var AdmissionConfig|null $config */
            $config = AdmissionConfig::where('school_id', $schoolId)
                ->lockForUpdate()   // blocks any concurrent generate() for same school
                ->first();

            // ── Edge case: no config row exists yet ──────────────────────────────
            if (! $config) {
                throw new RuntimeException(
                    "No admission config found for school ID {$schoolId}. " .
                    "Please configure it in the admin panel first."
                );
            }

            // ── Edge case: school doesn't use admission numbers ──────────────────
            if (! $config->enabled) {
                return null;
            }

            // ── 2. Determine the year string ─────────────────────────────────────
            $year = $this->resolveYear($config, $academicYear);

            // ── 3. Handle yearly sequence reset ──────────────────────────────────
            if ($config->reset_yearly) {
                $resetYear = $config->year_format === 'YY'
                    ? substr($year, -2)
                    : $year;

                if ($config->last_reset_year !== $resetYear) {
                    // New academic year detected — reset sequence
                    $config->current_sequence = $config->sequence_start - 1;
                    $config->last_reset_year  = $resetYear;
                }
            }

            // ── 4. Increment sequence atomically ─────────────────────────────────
            $nextSequence = $config->current_sequence + 1;

            // ── Edge case: sequence_start migration guard ─────────────────────────
            // If a school migrated with existing students (e.g. last paper number was 456),
            // sequence_start was set to 456. current_sequence starts at 0.
            // On first call: nextSequence = 1, but we must not go below sequence_start.
            if ($nextSequence < $config->sequence_start) {
                $nextSequence = $config->sequence_start;
            }

            // ── 5. Build the formatted number ─────────────────────────────────────
            $admissionNumber = $config->buildNumber($nextSequence, $year);

            // ── 6. Uniqueness double-check ────────────────────────────────────────
            // Extremely unlikely to fail given the lock, but guards against
            // manual overrides or imported data clashing with the sequence.
            $clash = Student::where('school_id', $schoolId)
                ->where('admission_number', $admissionNumber)
                ->exists();

            if ($clash) {
                // Skip this number and try the next one
                $nextSequence++;
                $admissionNumber = $config->buildNumber($nextSequence, $year);
            }

            // ── 7. Persist the updated sequence ──────────────────────────────────
            $config->current_sequence = $nextSequence;
            $config->save();

            return $admissionNumber;
        });
    }

    /**
     * Assign a MANUAL admission number to a school.
     *
     * Used by schools that have allow_manual_override = true
     * (e.g. legacy alpha-numeric systems like "A3456").
     *
     * @throws RuntimeException  If manual override is not allowed or number already taken
     */
    public function assignManual(int $schoolId, string $admissionNumber): string
    {
        $config = AdmissionConfig::where('school_id', $schoolId)->firstOrFail();

        if (! $config->allow_manual_override) {
            throw new RuntimeException(
                "Manual admission number assignment is not enabled for school ID {$schoolId}."
            );
        }

        // Uniqueness check
        $clash = Student::where('school_id', $schoolId)
            ->where('admission_number', $admissionNumber)
            ->exists();

        if ($clash) {
            throw new RuntimeException(
                "Admission number '{$admissionNumber}' is already assigned to another student in this school."
            );
        }

        return $admissionNumber;
    }

    /**
     * Preview what the next admission number would look like WITHOUT saving anything.
     * Safe to call as many times as needed for the admin UI live preview.
     */
    public function preview(int $schoolId, ?string $year = null): ?string
    {
        $config = AdmissionConfig::where('school_id', $schoolId)->first();

        if (! $config || ! $config->enabled) {
            return null;
        }

        return $config->previewNextNumber($year);
    }

    /**
     * Reset the sequence for a school to a specific value.
     * Used by admins when migrating from paper records.
     *
     * @param  int  $schoolId
     * @param  int  $startFrom  e.g. 456 if paper records end at 456
     */
    public function resetSequence(int $schoolId, int $startFrom): void
    {
        AdmissionConfig::where('school_id', $schoolId)->update([
            'current_sequence' => $startFrom,
            'sequence_start'   => $startFrom + 1,
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────────

    private function resolveYear(AdmissionConfig $config, ?AcademicYear $academicYear): string
    {
        if (! $config->include_year) {
            return now()->format('Y'); // value ignored in pattern anyway
        }

        if ($academicYear) {
            // Use the academic year's start year
            return \Carbon\Carbon::parse($academicYear->start_date)->format('Y');
        }

        // Fallback to current calendar year
        return now()->format('Y');
    }
}