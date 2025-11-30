<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Classroom extends Model
{
    use HasFactory;

    // Updated table name - now uses 'classrooms' instead of 'classes'
    protected $table = 'classrooms';
    
    protected $fillable = [
        'school_id', 
        'class_name', 
        'capacity'
    ];

    protected $appends = [
        'student_count',
        'total_capacity',
        'remaining_capacity',
        'has_streams'
    ];

    // =====================
    // RELATIONSHIPS
    // =====================

    /**
     * Get the school that owns this classroom.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the streams for this classroom (if school has streams).
     * Used for schools with streams enabled.
     */
    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class, 'class_id');
    }

    /**
     * Get all teachers assigned to this classroom (for non-stream schools).
     * Used for schools WITHOUT streams enabled.
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(
            Teacher::class, 
            'classroom_teacher', 
            'classroom_id', 
            'teacher_id'
        )
        ->withPivot('is_class_teacher')
        ->withTimestamps();
    }

    /**
     * Get the class teacher for this classroom (returns single teacher or null).
     * Used for non-stream schools.
     */
    public function classTeacher()
    {
        return $this->teachers()
            ->wherePivot('is_class_teacher', true)
            ->first();
    }

    /**
     * Get all students in this classroom.
     * For non-stream schools: students are directly assigned to classroom
     * For stream schools: students are in streams under this classroom
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    // =====================
    // ATTRIBUTE ACCESSORS
    // =====================

    /**
     * Total number of students in this classroom.
     * Works for both stream and non-stream schools.
     */
    public function getStudentCountAttribute()
    {
        return $this->students()->count();
    }

    /**
     * Total capacity of this classroom.
     * For stream schools: sum of all stream capacities
     * For non-stream schools: classroom capacity
     */
    public function getTotalCapacityAttribute()
    {
        if ($this->has_streams) {
            return $this->streams()->sum('capacity');
        }
        return $this->capacity ?? 0;
    }

    /**
     * Remaining capacity available.
     */
    public function getRemainingCapacityAttribute()
    {
        return max(0, $this->total_capacity - $this->student_count);
    }

    /**
     * Check if the school has streams enabled.
     */
    public function getHasStreamsAttribute()
    {
        return $this->school && $this->school->has_streams;
    }

    // =====================
    // HELPER METHODS - NON-STREAM SCHOOLS
    // =====================

    /**
     * Assign a teacher to this classroom (non-stream schools only).
     * 
     * @param int $teacherId
     * @param bool $isClassTeacher
     * @return $this
     * @throws \Exception
     */
    public function assignTeacher($teacherId, $isClassTeacher = false)
    {
        if ($this->has_streams) {
            throw new \Exception('Cannot assign teacher directly to a classroom with streams. Assign to streams instead.');
        }

        if ($isClassTeacher) {
            $existing = $this->teachers()
                ->wherePivot('is_class_teacher', true)
                ->first();
            
            if ($existing && $existing->id !== $teacherId) {
                $this->teachers()->updateExistingPivot(
                    $existing->id, 
                    ['is_class_teacher' => false]
                );
            }
        }

        $this->teachers()->syncWithoutDetaching([
            $teacherId => ['is_class_teacher' => $isClassTeacher]
        ]);

        return $this;
    }

    /**
     * Remove a teacher from this classroom (non-stream schools only).
     * 
     * @param int $teacherId
     * @return $this
     */
    public function removeTeacher($teacherId)
    {
        $this->teachers()->detach($teacherId);
        return $this;
    }

    /**
     * Set a teacher as the class teacher (non-stream schools only).
     * Automatically removes class teacher designation from any other teacher.
     * 
     * @param int $teacherId
     * @return $this
     * @throws \Exception
     */
    public function setClassTeacher($teacherId)
    {
        if ($this->has_streams) {
            throw new \Exception('Cannot set class teacher for a classroom with streams. Set class teacher on streams instead.');
        }

        // Remove class teacher from all teachers
        $this->teachers()
            ->wherePivot('is_class_teacher', true)
            ->each(function ($teacher) {
                $this->teachers()->updateExistingPivot(
                    $teacher->id, 
                    ['is_class_teacher' => false]
                );
            });

        // Set the new class teacher
        $this->teachers()->syncWithoutDetaching([
            $teacherId => ['is_class_teacher' => true]
        ]);

        return $this;
    }

    /**
     * Remove the class teacher from this classroom (non-stream schools only).
     * 
     * @return $this
     */
    public function removeClassTeacher()
    {
        $classTeacher = $this->classTeacher;
        
        if ($classTeacher) {
            $this->teachers()->updateExistingPivot(
                $classTeacher->id, 
                ['is_class_teacher' => false]
            );
        }

        return $this;
    }

    // =====================
    // QUERY SCOPES
    // =====================

    /**
     * Scope to get classrooms with streams (for schools with streams).
     */
    public function scopeWithStreams($query)
    {
        return $query->with('streams', 'streams.students', 'streams.classTeacher');
    }

    /**
     * Scope to get classrooms with teachers (for schools without streams).
     */
    public function scopeWithTeachers($query)
    {
        return $query->with('teachers');
    }

    /**
     * Scope to get classrooms for a specific school.
     */
    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }
}