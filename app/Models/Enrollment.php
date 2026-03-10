<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'academic_year_id',
        'enrollment_type',
        'status',

        // Student personal info
        'first_name',
        'last_name',
        'middle_name',
        'date_of_birth',
        'gender',
        'nationality',
        'religion',
        'birth_certificate_number',
        'special_needs',
        'special_needs_details',

        // Parent info
        'parent_first_name',
        'parent_last_name',
        'parent_phone',
        'parent_email',
        'parent_national_id',
        'parent_relationship',
        'parent_occupation',
        'parent_address',

        // Transfer info
        'is_transfer',
        'previous_school_name',
        'previous_school_address',
        'previous_admission_number',
        'leaving_certificate_number',
        'last_class_attended',

        // Government placement fields
        // Only populated when enrollment_type = 'government_placement'
        'assessment_index_number',
        'placement_year',
        'placement_reference_code',
        'placement_school_name',
        'placement_verification_status',
        'placement_verification_notes',
        'placement_verified_by',
        'placement_verified_at',

        // Class preference — exact DB column names
        'applying_for_classroom_id',
        'applying_for_stream_id',

        // Documents
        'documents',

        // Workflow
        'applied_at',
        'reviewed_at',
        'approved_at',
        'rejected_at',
        'reviewed_by',
        'approved_by',
        'rejection_reason',
        'admin_notes',

        // Post-approval links (all null until approved)
        'student_id',
        'user_id',
        'assigned_classroom_id',
        'assigned_stream_id',
    ];

    protected $casts = [
        'documents'              => 'array',
        'special_needs'          => 'boolean',
        'is_transfer'            => 'boolean',
        'date_of_birth'          => 'date',
        'applied_at'             => 'datetime',
        'reviewed_at'            => 'datetime',
        'approved_at'            => 'datetime',
        'rejected_at'            => 'datetime',
        'placement_verified_at'  => 'datetime',
        'status_last_checked_at' => 'datetime',
        'placement_year'         => 'integer',
        'status_check_count'     => 'integer',
    ];

    // Never expose the tracking token in list API responses.
    // It is returned ONCE on store() and sent in the confirmation email.
    protected $hidden = ['tracking_token'];

    // ── Auto-generate tracking token on creation ──────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Enrollment $enrollment) {
            $enrollment->tracking_token = \Illuminate\Support\Str::uuid()->toString();
        });
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applyingForClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'applying_for_classroom_id');
    }

    public function applyingForStream(): BelongsTo
    {
        return $this->belongsTo(Stream::class, 'applying_for_stream_id');
    }

    public function assignedClassroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'assigned_classroom_id');
    }

    public function assignedStream(): BelongsTo
    {
        return $this->belongsTo(Stream::class, 'assigned_stream_id');
    }

    // ── Computed Attributes ───────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function getParentFullNameAttribute(): string
    {
        return "{$this->parent_first_name} {$this->parent_last_name}";
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->status === 'approved';
    }

    public function getIsRejectedAttribute(): bool
    {
        return $this->status === 'rejected';
    }

    public function getIsPendingAttribute(): bool
    {
        return in_array($this->status, ['submitted', 'under_review']);
    }

    // ── Status Transition Helpers ─────────────────────────────────────────────

    public function markAsSubmitted(): void
    {
        $this->update([
            'status'     => 'submitted',
            'applied_at' => now(),
        ]);
    }

    public function markAsUnderReview(int $reviewerId): void
    {
        $this->update([
            'status'      => 'under_review',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ]);
    }

    public function markAsApproved(int $approverId): void
    {
        $this->update([
            'status'      => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }

    public function markAsRejected(int $reviewerId, string $reason): void
    {
        $this->update([
            'status'           => 'rejected',
            'reviewed_by'      => $reviewerId,
            'reviewed_at'      => now(),
            'rejected_at'      => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function markAsWaitlisted(): void
    {
        $this->update(['status' => 'waitlisted']);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeOwnedBy($query, \App\Models\User $user)
    {
        // A parent owns enrollments where their phone or email matches
        // This is how we scope what a logged-in parent can see
        return $query->where(function ($q) use ($user) {
            $q->where('parent_phone', $user->phone)
              ->orWhere(function ($q2) use ($user) {
                  $q2->whereNotNull('parent_email')
                     ->where('parent_email', $user->email);
              });
        });
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['submitted', 'under_review']);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeWaitlisted($query)
    {
        return $query->where('status', 'waitlisted');
    }

    public function scopeForAcademicYear($query, int $yearId)
    {
        return $query->where('academic_year_id', $yearId);
    }

    public function scopeTransfers($query)
    {
        return $query->where('is_transfer', true);
    }

    public function scopeGovernmentPlacements($query)
    {
        return $query->where('enrollment_type', 'government_placement');
    }

    public function scopePendingPlacementVerification($query)
    {
        return $query->where('enrollment_type', 'government_placement')
                     ->where('placement_verification_status', 'pending');
    }

    // ── Government Placement Helpers ──────────────────────────────────────────

    public function isGovernmentPlacement(): bool
    {
        return $this->enrollment_type === 'government_placement';
    }

    public function isPlacementVerified(): bool
    {
        return $this->placement_verification_status === 'verified';
    }

    public function isPlacementDisputed(): bool
    {
        return $this->placement_verification_status === 'disputed';
    }

    /**
     * A government placement enrollment cannot be approved
     * until the placement is verified by admin.
     */
    public function canBeApproved(): bool
    {
        if ($this->isGovernmentPlacement()) {
            return $this->isPlacementVerified();
        }
        return true;
    }

    // ── Status Tracking ───────────────────────────────────────────────────────

    /**
     * Record a status check hit.
     * Called by the track() endpoint to count lookups per enrollment.
     */
    public function recordStatusCheck(): void
    {
        // Use DB directly to avoid triggering model events
        \Illuminate\Support\Facades\DB::table('enrollments')
            ->where('id', $this->id)
            ->increment('status_check_count');

        \Illuminate\Support\Facades\DB::table('enrollments')
            ->where('id', $this->id)
            ->update(['status_last_checked_at' => now()]);
    }
}