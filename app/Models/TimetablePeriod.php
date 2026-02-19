<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimetablePeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'teacher_id',
        'subject_assignment_id',
        'classroom_id',
        'stream_id',
        'academic_year_id',
        'term_id',
        'day_of_week',
        'period_number',
        'start_time',
        'end_time',
        'has_conflict',
        'conflicting_periods'
    ];

    protected $casts = [
        'has_conflict' => 'boolean',
        'conflicting_periods' => 'array',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
    ];

    // Relationships
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subjectAssignment(): BelongsTo
    {
        return $this->belongsTo(SubjectAssignment::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    // Helper methods
    public function getTimeSlot(): string
    {
        return "{$this->day_of_week} Period {$this->period_number} ({$this->start_time->format('H:i')} - {$this->end_time->format('H:i')})";
    }

    public function detectConflicts()
    {
        // Check for teacher conflicts
        $teacherConflicts = self::where('teacher_id', $this->teacher_id)
            ->where('academic_year_id', $this->academic_year_id)
            ->where('term_id', $this->term_id)
            ->where('day_of_week', $this->day_of_week)
            ->where('period_number', $this->period_number)
            ->where('id', '!=', $this->id)
            ->exists();

        // Check for classroom/stream conflicts
        $locationConflict = false;
        if ($this->classroom_id) {
            $locationConflict = self::where('classroom_id', $this->classroom_id)
                ->where('academic_year_id', $this->academic_year_id)
                ->where('term_id', $this->term_id)
                ->where('day_of_week', $this->day_of_week)
                ->where('period_number', $this->period_number)
                ->where('id', '!=', $this->id)
                ->exists();
        } elseif ($this->stream_id) {
            $locationConflict = self::where('stream_id', $this->stream_id)
                ->where('academic_year_id', $this->academic_year_id)
                ->where('term_id', $this->term_id)
                ->where('day_of_week', $this->day_of_week)
                ->where('period_number', $this->period_number)
                ->where('id', '!=', $this->id)
                ->exists();
        }

        $this->has_conflict = $teacherConflicts || $locationConflict;
        $this->save();

        return $this->has_conflict;
    }
}