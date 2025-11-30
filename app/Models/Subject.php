<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $fillable = [
        'school_id', 
        'name', 
        'code',
        'curriculum_type', // 'CBC' or '8-4-4'
        'grade_level',     // e.g., 'Grade 1', 'Grade 7', 'Form 1', 'Standard 8'
        'category',        // e.g., 'Languages', 'Sciences', 'Humanities', 'Technical'
        'is_core'          // Whether this is a core/compulsory subject
    ];

    /**
     * Get the school that owns the subject.
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The teachers that teach the subject.
     */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class);
    }

    /**
     * The streams that this subject is taught in.
     * Only for schools with streams enabled.
     */
    public function streams(): BelongsToMany
    {
        return $this->belongsToMany(Stream::class, 'subject_stream')
            ->withPivot('teacher_id', 'weekly_periods', 'assignment_type')
            ->withTimestamps();
    }

    /**
     * Scope a query to only include CBC subjects.
     */
    public function scopeCbc($query)
    {
        return $query->where('curriculum_type', 'CBC');
    }

    /**
     * Scope a query to only include 8-4-4 subjects.
     */
    public function scopeLegacy($query)
    {
        return $query->where('curriculum_type', '8-4-4');
    }

    /**
     * Scope a query to only include core subjects.
     */
    public function scopeCore($query)
    {
        return $query->where('is_core', true);
    }

    /**
     * Scope a query to only include elective subjects.
     */
    public function scopeElective($query)
    {
        return $query->where('is_core', false);
    }
}