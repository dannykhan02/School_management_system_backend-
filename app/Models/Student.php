<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'school_id',
        'class_id',
        'parent_id',
        'status',
        // ── Admission number fields ───────────────────────────────────────────
        'admission_number',              // null for schools with enabled=false
        'admission_number_is_manual',   // true if admin typed it manually
        'admitted_academic_year_id',    // the year they were admitted
        // ─────────────────────────────────────────────────────────────────────
        'date_of_birth',
        'gender',
    ];

    protected $casts = [
        'admission_number_is_manual' => 'boolean',
        'date_of_birth'              => 'date',
    ];

    // ── Relationships ────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ParentModel::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(StudentClass::class);
    }

    public function admittedAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'admitted_academic_year_id');
    }

    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * Returns the admission number for display.
     * If the school doesn't use admission numbers, returns a dash.
     */
    public function getAdmissionDisplayAttribute(): string
    {
        return $this->admission_number ?? '—';
    }

    /**
     * Whether this student has an admission number assigned.
     */
    public function hasAdmissionNumber(): bool
    {
        return ! is_null($this->admission_number);
    }

    // ── Scopes ───────────────────────────────────────────────────────────────────

    public function scopeWithAdmissionNumber($query)
    {
        return $query->whereNotNull('admission_number');
    }

    public function scopeWithoutAdmissionNumber($query)
    {
        return $query->whereNull('admission_number');
    }

    public function scopeManuallyAssigned($query)
    {
        return $query->where('admission_number_is_manual', true);
    }
}