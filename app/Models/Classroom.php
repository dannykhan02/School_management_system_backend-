<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Classroom extends Model
{
    use HasFactory;

    protected $table = 'classes';
    
    protected $fillable = [
        'school_id', 
        'class_name', 
        'capacity'
    ];

    protected $appends = [
        'student_count',
        'total_capacity',
        'remaining_capacity'
    ];

    /**
     * Get the school that owns this classroom.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the streams for this classroom.
     */
    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class, 'class_id');
    }

    /**
     * Get the students in all streams of this classroom.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    /**
     * Get the total number of students in all streams of this classroom.
     */
    public function getStudentCountAttribute()
    {
        return $this->students()->count();
    }

    /**
     * Get the total capacity of all streams in this classroom.
     */
    public function getTotalCapacityAttribute()
    {
        return $this->streams()->sum('capacity');
    }

    /**
     * Get the remaining capacity in all streams of this classroom.
     */
    public function getRemainingCapacityAttribute()
    {
        return max(0, $this->total_capacity - $this->student_count);
    }
}