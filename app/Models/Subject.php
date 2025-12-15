<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'is_core'         
    ];

    protected $casts = [
        'is_core' => 'boolean',
    ];

    /**
     * Each subject belongs to a school.
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Direct teachers pivot removed.
     * Teachers now connect through subject_assignments.
     */
    public function assignments()
    {
        return $this->hasMany(SubjectAssignment::class);
    }

    /**
     * If you still want teachers directly:
     * returns teachers associated through assignments.
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
     * Also through subject_assignments.
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

    /**
     * Query scopes below.
     */

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

    /**
     * Logic to check if a subject is offered by its school.
     */
    public function isOffered()
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
