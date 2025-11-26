<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'school_id', 
        'year', 
        'term', 
        'start_date', 
        'end_date',
        'curriculum_type', // 'CBC' or '8-4-4'
        'is_active',        // Boolean to mark the current academic year
    ];

    /**
     * Get the school that owns the academic year.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the student classes for the academic year.
     */
    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class);
    }

    /**
     * Scope a query to only include active academic years.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include academic years of a given curriculum type.
     */
    public function scopeOfCurriculum($query, string $type)
    {
        return $query->where('curriculum_type', $type);
    }
}