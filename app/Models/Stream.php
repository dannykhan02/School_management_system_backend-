<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stream extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'class_id',
        'name',
        'capacity',
        'class_teacher_id',
    ];

    /**
     * Get the school that owns this stream.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the classroom that this stream belongs to.
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'class_id');
    }

    /**
     * Get the class teacher for this stream.
     */
    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'class_teacher_id');
    }

    /**
     * Get all teachers assigned to this stream.
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'stream_teacher');
    }

    /**
     * Get the students in this stream.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'stream_id');
    }

    /**
     * Get the number of students in this stream.
     */
    public function getStudentCountAttribute()
    {
        return $this->students()->count();
    }

    /**
     * Get the remaining capacity in this stream.
     */
    public function getRemainingCapacityAttribute()
    {
        return max(0, $this->capacity - $this->student_count);
    }
}