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
        'grade_level',     // e.g., 'Grade 1-3', 'Grade 7-9', 'Form 1-4', 'Standard 5-8'
        'level',           // e.g., 'Pre-Primary', 'Primary', 'Junior Secondary', 'Senior Secondary', 'Secondary'
        'pathway',         // e.g., 'STEM', 'Arts', 'Social Sciences' (for Senior Secondary only)
        'category',        // e.g., 'Languages', 'Sciences', 'Humanities', 'Technical'
        'is_core'          // Whether this is a core/compulsory subject
    ];

    protected $casts = [
        'is_core' => 'boolean',
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

    /**
     * Scope a query to filter by educational level.
     */
    public function scopeByLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope a query to only include primary level subjects.
     */
    public function scopePrimary($query)
    {
        return $query->where('level', 'Primary');
    }

    /**
     * Scope a query to only include pre-primary level subjects.
     */
    public function scopePrePrimary($query)
    {
        return $query->where('level', 'Pre-Primary');
    }

    /**
     * Scope a query to only include junior secondary level subjects.
     */
    public function scopeJuniorSecondary($query)
    {
        return $query->where('level', 'Junior Secondary');
    }

    /**
     * Scope a query to only include senior secondary level subjects.
     */
    public function scopeSeniorSecondary($query)
    {
        return $query->where('level', 'Senior Secondary');
    }

    /**
     * Scope a query to only include secondary level subjects (8-4-4).
     */
    public function scopeSecondary($query)
    {
        return $query->where('level', 'Secondary');
    }

    /**
     * Scope a query to filter by category.
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to filter by pathway (for Senior Secondary).
     */
    public function scopeByPathway($query, $pathway)
    {
        return $query->where('pathway', $pathway);
    }

    /**
     * Check if the subject is offered by the school.
     */
    public function isOffered()
    {
        if (!$this->school) {
            return false;
        }

        // Check if the school offers the curriculum type
        if ($this->curriculum_type === 'CBC') {
            if (!in_array($this->school->primary_curriculum, ['CBC', 'Both']) && 
                !in_array($this->school->secondary_curriculum ?? $this->school->primary_curriculum, ['CBC', 'Both'])) {
                return false;
            }
        } elseif ($this->curriculum_type === '8-4-4') {
            if (!in_array($this->school->primary_curriculum, ['8-4-4', 'Both']) && 
                !in_array($this->school->secondary_curriculum ?? $this->school->primary_curriculum, ['8-4-4', 'Both'])) {
                return false;
            }
        }

        // Check if the school offers the level
        $levelField = 'has_' . strtolower(str_replace(' ', '_', $this->level));
        if (!isset($this->school->$levelField) || $this->school->$levelField !== true) {
            return false;
        }

        // For Senior Secondary, check if the school offers the pathway
        if ($this->level === 'Senior Secondary' && $this->pathway) {
            if (!$this->school->offersPathway($this->pathway)) {
                return false;
            }
        }

        return true;
    }
}