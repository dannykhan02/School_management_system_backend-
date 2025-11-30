<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'school_id',
        'qualification',
        'employment_type',
        'tsc_number',
        'specialization',
        'curriculum_specialization',
        'max_subjects',
        'max_classes',
    ];

    public $timestamps = true;

    /**
     * Teacher's user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Teacher's school.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Subjects taught.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class);
    }

    // ---------------------------
    // STREAM SCHOOLS
    // ---------------------------

    /**
     * Streams where teacher is class teacher.
     */
    public function classTeacherStreams(): HasMany
    {
        return $this->hasMany(Stream::class, 'class_teacher_id');
    }

    /**
     * Streams where teacher teaches.
     */
    public function teachingStreams(): BelongsToMany
    {
        return $this->belongsToMany(Stream::class, 'stream_teacher', 'teacher_id', 'stream_id')
                    ->withTimestamps();
    }

    // ---------------------------
    // NON-STREAM SCHOOLS
    // ---------------------------

    /**
     * Classrooms where teacher teaches.
     */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_teacher', 'teacher_id', 'classroom_id')
                    ->withPivot('is_class_teacher')
                    ->withTimestamps();
    }

    /**
     * Classroom where teacher is class teacher.
     */
    public function classTeacherClassroom()
    {
        return $this->classrooms()->wherePivot('is_class_teacher', true)->first();
    }

    // ---------------------------
    // HELPER METHODS
    // ---------------------------

    /**
     * Check if teacher is a class teacher.
     */
    public function isClassTeacher(): bool
    {
        $hasStreams = $this->school && $this->school->has_streams;
        return $hasStreams ? $this->classTeacherStreams()->exists()
                           : $this->classrooms()->wherePivot('is_class_teacher', true)->exists();
    }

    /**
     * Get the number of assignments.
     */
    public function getAssignmentCount(): int
    {
        $hasStreams = $this->school && $this->school->has_streams;
        return $hasStreams ? $this->teachingStreams()->count()
                           : $this->classrooms()->count();
    }

    /**
     * Get streams where teacher is class teacher.
     */
    public function getStreamsAsClassTeacher()
    {
        return $this->classTeacherStreams()->with('classroom', 'students')->get();
    }

    /**
     * Get streams where teacher teaches.
     */
    public function getStreamsAsTeacher()
    {
        return $this->teachingStreams()->with('classroom', 'students')->get();
    }
}