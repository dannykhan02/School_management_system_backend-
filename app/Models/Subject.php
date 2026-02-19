<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'school_id', 
        'name', 
        'code',
        'curriculum_type', 
        'grade_level',    
        'level',          
        'pathway',        
        'category',       
        'is_core',
        // New fields from discussion
        'is_kicd_compulsory',
        'learning_area',
        'minimum_weekly_periods',
        'maximum_weekly_periods',
        'prerequisite_subjects',
        'incompatible_subjects',
        'requires_lab',
        'cbc_pathway',
        'grade_levels',
        'kicd_code',
        'national_subject_id',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'is_kicd_compulsory' => 'boolean',
        'prerequisite_subjects' => 'array',
        'incompatible_subjects' => 'array',
        'requires_lab' => 'boolean',
        'grade_levels' => 'array',
    ];

    /**
     * Each subject belongs to a school.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Subject assignments.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    /**
     * Teachers associated through assignments.
     */
    public function teachers()
    {
        return $this->hasManyThrough(
            Teacher::class,
            SubjectAssignment::class,
            'subject_id',
            'id',
            'id',
            'teacher_id'
        );
    }

    /**
     * Streams for schools with streams enabled.
     */
    public function streams()
    {
        return $this->hasManyThrough(
            Stream::class,
            SubjectAssignment::class,
            'subject_id',
            'id',
            'id',
            'stream_id'
        );
    }

    // ---------------------------
    // NEW SCOPES FROM DISCUSSION
    // ---------------------------

    /**
     * Scope by learning area.
     */
    public function scopeByLearningArea($query, $area)
    {
        return $query->where('learning_area', $area);
    }

    /**
     * Scope for KICD compulsory subjects.
     */
    public function scopeKICDCompulsory($query)
    {
        return $query->where('is_kicd_compulsory', true);
    }

    /**
     * Scope for CBC pathway.
     */
    public function scopeForPathway($query, $pathway)
    {
        return $query->where(function($q) use ($pathway) {
            $q->where('cbc_pathway', $pathway)
              ->orWhere('cbc_pathway', 'All');
        });
    }

    // ---------------------------
    // EXISTING SCOPES
    // ---------------------------

    public function scopeCbc($query)
    {
        return $query->where('curriculum_type', 'CBC');
    }

    public function scopeLegacy($query)
    {
        return $query->where('curriculum_type', '8-4-4');
    }

    public function scopeCore($query)
    {
        return $query->where('is_core', true);
    }

    public function scopeElective($query)
    {
        return $query->where('is_core', false);
    }

    public function scopeByLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    public function scopePrimary($query)
    {
        return $query->where('level', 'Primary');
    }

    public function scopePrePrimary($query)
    {
        return $query->where('level', 'Pre-Primary');
    }

    public function scopeJuniorSecondary($query)
    {
        return $query->where('level', 'Junior Secondary');
    }

    public function scopeSeniorSecondary($query)
    {
        return $query->where('level', 'Senior Secondary');
    }

    public function scopeSecondary($query)
    {
        return $query->where('level', 'Secondary');
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByPathway($query, $pathway)
    {
        return $query->where('pathway', $pathway);
    }

    // ---------------------------
    // HELPER METHODS
    // ---------------------------

    /**
     * Get learning area.
     */
    public function getLearningArea(): string
    {
        return $this->learning_area;
    }

    /**
     * Check if subject is KICD compulsory.
     */
    public function isKICDCompulsory(): bool
    {
        return $this->is_kicd_compulsory;
    }

    /**
     * Get recommended weekly periods.
     */
    public function getRecommendedWeeklyPeriods(): int
    {
        return $this->minimum_weekly_periods ?? 5;
    }

    /**
     * Check if subject is valid for grade level.
     */
    public function isValidForGrade($gradeLevel): bool
    {
        if (empty($this->grade_levels)) {
            return true;
        }
        
        return in_array($gradeLevel, $this->grade_levels);
    }

    /**
     * Get prerequisite subjects.
     */
    public function getPrerequisiteSubjects()
    {
        if (empty($this->prerequisite_subjects)) {
            return collect();
        }
        
        return self::whereIn('id', $this->prerequisite_subjects)->get();
    }

    /**
     * Get incompatible subjects.
     */
    public function getIncompatibleSubjects()
    {
        if (empty($this->incompatible_subjects)) {
            return collect();
        }
        
        return self::whereIn('id', $this->incompatible_subjects)->get();
    }

    /**
     * Check if subject requires lab.
     */
    public function requiresLab(): bool
    {
        return $this->requires_lab;
    }

    /**
     * Logic to check if a subject is offered by its school.
     */
    public function isOffered(): bool
    {
        if (!$this->school) {
            return false;
        }

        // Check curriculum compatibility
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

        // Check level availability
        $levelField = 'has_' . strtolower(str_replace(' ', '_', $this->level));
        if (!isset($this->school->$levelField) || $this->school->$levelField !== true) {
            return false;
        }

        // Check pathway for Senior Secondary
        if ($this->level === 'Senior Secondary' && $this->pathway) {
            if (!$this->school->offersPathway($this->pathway)) {
                return false;
            }
        }

        return true;
    }
}