<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'academic_year_id',  // This was missing!
        'stream_id',
        'weekly_periods',      // Number of periods per week for this subject
        'assignment_type',     // 'main_teacher', 'assistant_teacher', 'substitute'
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
     * Get stream (class) for this assignment.
     */
    public function stream(): BelongsTo
    {
        return $this->belongsTo(Stream::class);
    }
}