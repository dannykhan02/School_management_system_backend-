<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubjectAssignment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'teacher_id',
        'subject_id',
        'academic_year_id',
        'stream_id',
        'weekly_periods',
        'assignment_type',
        'classroom_id',
        'is_outside_specialization',
        'subject_type',
        'assignment_priority',
        'timetable_periods',
        'has_conflicts',
        'conflict_details',
        'is_kicd_compliant',
        'learning_area',
        'workload_impact_score',
        'batch_assignment_id',
        'is_bulk_assignment',
        // 👇 NEW FIELDS
        'school_id',
        'term_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'weekly_periods' => 'integer',
        'timetable_periods' => 'array',
        'has_conflicts' => 'boolean',
        'conflict_details' => 'array',
        'is_kicd_compliant' => 'boolean',
        'is_bulk_assignment' => 'boolean',
        'is_outside_specialization' => 'boolean',
        'assignment_priority' => 'integer',
        'workload_impact_score' => 'integer',
    ];

    /**
     * Get subject for this assignment.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Get teacher for this assignment.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * Get academic year for this assignment.
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get classroom for this assignment (for schools without streams).
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /**
     * Get stream (class) for this assignment (for schools with streams).
     */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    /**
     * 👇 NEW RELATIONSHIP
     * Get the term associated with this assignment.
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * Relationship to timetable periods.
     */
    public function timetablePeriodsRelation(): HasMany
    {
        return $this->hasMany(TimetablePeriod::class);
    }

    /**
     * Get the classroom or stream for this assignment.
     * This method determines which relationship to use based on school configuration.
     */
    public function classOrStream()
    {
        if ($this->classroom) {
            return $this->classroom;
        }
        return $this->stream;
    }

    // ---------------------------
    // HELPER METHODS
    // ---------------------------

    /**
     * Check if assignment has conflicts.
     */
    public function hasConflicts(): bool
    {
        return $this->has_conflicts;
    }

    /**
     * Check specialization match between teacher and subject.
     */
    public function checkSpecializationMatch(): bool
    {
        if (!$this->teacher || !$this->subject) {
            return false;
        }

        $matcher = new \App\Services\SpecializationMatcher();
        $result = $matcher->checkMatch($this->teacher, $this->subject);

        return $result['matches'] ?? false;
    }

    /**
     * Calculate workload impact of this assignment.
     */
    public function calculateWorkloadImpact(): int
    {
        return $this->weekly_periods ?? 5;
    }

    /**
     * Accessor for workload impact.
     */
    public function getWorkloadImpactAttribute(): int
    {
        return $this->calculateWorkloadImpact();
    }

    /**
     * Check if assignment is KICD compliant.
     */
    public function isKICDCompliant(): bool
    {
        return $this->is_kicd_compliant;
    }

    /**
     * Get assignment priority level.
     */
    public function getPriorityLevel(): string
    {
        if ($this->assignment_priority >= 90) {
            return 'High';
        } elseif ($this->assignment_priority >= 70) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }

    /**
     * Check if assignment is outside teacher's specialization.
     */
    public function isOutsideSpecialization(): bool
    {
        return $this->is_outside_specialization;
    }

    /**
     * Mark assignment as having conflicts.
     */
    public function markAsConflict($details = []): void
    {
        $this->has_conflicts = true;
        $this->conflict_details = $details;
        $this->save();
    }

    /**
     * Resolve conflicts in assignment.
     */
    public function resolveConflicts(): void
    {
        $this->has_conflicts = false;
        $this->conflict_details = null;
        $this->save();
    }

    /**
     * Get timetable periods as collection.
     */
    public function getTimetablePeriodsCollection()
    {
        if (empty($this->timetable_periods)) {
            return collect();
        }

        return collect($this->timetable_periods);
    }
}