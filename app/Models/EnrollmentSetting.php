<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'academic_year_id',

        // Enrollment window
        'enrollment_open',
        'open_date',
        'close_date',

        // Capacity
        'max_capacity',
        'current_enrolled',
        'allow_waitlist',

        // Approval behaviour
        'auto_approve',
        'required_documents',

        // Enrollment types allowed — DB uses individual booleans, not JSON array
        'accept_new_students',
        'accept_transfers',
        'accept_returning',

        // Notifications
        'notify_parent_on_submit',
        'notify_parent_on_approval',
        'notify_parent_on_rejection',
        'notify_admin_on_new_application',
    ];

    protected $casts = [
        'enrollment_open'                 => 'boolean',
        'allow_waitlist'                  => 'boolean',
        'auto_approve'                    => 'boolean',
        'accept_new_students'             => 'boolean',
        'accept_transfers'                => 'boolean',
        'accept_returning'                => 'boolean',
        'notify_parent_on_submit'         => 'boolean',
        'notify_parent_on_approval'       => 'boolean',
        'notify_parent_on_rejection'      => 'boolean',
        'notify_admin_on_new_application' => 'boolean',
        'required_documents'              => 'array',
        'open_date'                       => 'date',
        'close_date'                      => 'date',
        'max_capacity'                    => 'integer',
        'current_enrolled'                => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    // ── State Checks ──────────────────────────────────────────────────────────

    /**
     * Is the school currently accepting applications for this term?
     *
     * Checks the master switch first, then the date window.
     * If no explicit open_date / close_date have been configured on this
     * record, we fall back to the parent academic year's start_date /
     * end_date so that enrollment automatically aligns with the term
     * boundaries — no manual housekeeping needed.
     */
    public function isAcceptingApplications(): bool
    {
        if (! $this->enrollment_open) {
            return false;
        }

        $today = now()->startOfDay();

        // Prefer explicitly-set dates; fall back to the term's own dates.
        $openDate  = $this->open_date  ?? $this->academicYear?->start_date;
        $closeDate = $this->close_date ?? $this->academicYear?->end_date;

        if ($openDate && $today->lt($openDate)) {
            return false; // term / enrollment window has not started yet
        }

        if ($closeDate && $today->gt($closeDate)) {
            return false; // term / enrollment window has already closed
        }

        return true;
    }

    /**
     * Has the school reached maximum capacity for this term?
     * max_capacity = 0 means unlimited.
     */
    public function isAtCapacity(): bool
    {
        if ($this->max_capacity === 0) {
            return false;
        }

        return $this->current_enrolled >= $this->max_capacity;
    }

    /**
     * How many spots are remaining for this term.
     * Returns null when capacity is unlimited (max_capacity = 0).
     */
    public function spotsRemaining(): ?int
    {
        if ($this->max_capacity === 0) {
            return null;
        }

        return max(0, $this->max_capacity - $this->current_enrolled);
    }

    /**
     * Does this setting belong to the given calendar year?
     * Useful when the frontend wants to group terms under their year,
     * e.g. 2026 → [Term 1, Term 2, Term 3].
     */
    public function isSameCalendarYear(int $year): bool
    {
        return $this->academicYear?->year === $year;
    }

    /**
     * A human-readable label combining year and term,
     * e.g. "2026 – Term 1". Handy for logs and API responses.
     */
    public function termLabel(): string
    {
        $year = $this->academicYear?->year  ?? '—';
        $term = $this->academicYear?->term  ?? '—';

        return "{$year} – {$term}";
    }

    /**
     * Check if a specific enrollment type is allowed this term.
     * Uses the individual boolean columns that exist in the DB.
     */
    public function allowsEnrollmentType(string $type): bool
    {
        return match ($type) {
            'new'                  => $this->accept_new_students,
            'transfer'             => $this->accept_transfers,
            'returning'            => $this->accept_returning,
            'government_placement' => true, // always allowed when enrollment is open
            default                => false,
        };
    }

    /**
     * Check if a required document slug has been declared as required.
     */
    public function requiresDocument(string $slug): bool
    {
        return in_array($slug, $this->required_documents ?? []);
    }

    /**
     * Atomically increment the enrolled counter.
     * Called inside the DB transaction in EnrollmentObserver.
     */
    public function incrementEnrolled(): void
    {
        $this->increment('current_enrolled');
    }

    /**
     * Decrement enrolled count when a student leaves or
     * an approval is reversed. Never goes below zero.
     */
    public function decrementEnrolled(): void
    {
        if ($this->current_enrolled > 0) {
            $this->decrement('current_enrolled');
        }
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->where('enrollment_open', true);
    }

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForAcademicYear($query, int $yearId)
    {
        return $query->where('academic_year_id', $yearId);
    }
}