<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name', 
        'address', 
        'school_type', 
        'city', 
        'code', 
        'phone', 
        'email', 
        'logo',
        'primary_curriculum', // 'CBC', '8-4-4', or 'Both'
        'secondary_curriculum', // 'CBC', '8-4-4', or 'Both'
        'has_streams', // Whether the school has streams enabled
        'has_pre_primary', // Whether the school offers Pre-Primary education
        'has_primary', // Whether the school offers Primary education
        'has_junior_secondary', // Whether the school offers Junior Secondary education
        'has_senior_secondary', // Whether the school offers Senior Secondary education
        'has_secondary', // Whether the school offers Secondary education (8-4-4)
        'senior_secondary_pathways', // JSON array of pathways: ['STEM', 'Arts', 'Social Sciences']
        'grade_levels', // JSON array of grade levels or class levels
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'has_streams' => 'boolean',
        'has_pre_primary' => 'boolean',
        'has_primary' => 'boolean',
        'has_junior_secondary' => 'boolean',
        'has_senior_secondary' => 'boolean',
        'has_secondary' => 'boolean',
        'senior_secondary_pathways' => 'array',
        'grade_levels' => 'array',
    ];

    /**
     * Get the academic years for the school.
     */
    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    /**
     * Get the users associated with the school.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the teachers for the school.
     */
    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    /**
     * Get the classrooms for the school.
     */
    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    /**
     * Get the students for the school.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * Get the parents for the school.
     */
    public function parents(): HasMany
    {
        return $this->hasMany(ParentModel::class);
    }

    /**
     * Get the subjects for the school.
     */
    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    /**
     * Get the streams for the school.
     */
    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }

    /**
     * Scope a query to only include CBC schools.
     */
    public function scopeCbc($query)
    {
        return $query->where('primary_curriculum', 'CBC')
                    ->orWhere('secondary_curriculum', 'CBC');
    }

    /**
     * Scope a query to only include 8-4-4 schools.
     */
    public function scopeLegacy($query)
    {
        return $query->where('primary_curriculum', '8-4-4')
                    ->orWhere('secondary_curriculum', '8-4-4');
    }

    /**
     * Scope a query to only include schools with Pre-Primary level.
     */
    public function scopeWithPrePrimary($query)
    {
        return $query->where('has_pre_primary', true);
    }

    /**
     * Scope a query to only include schools with Primary level.
     */
    public function scopeWithPrimary($query)
    {
        return $query->where('has_primary', true);
    }

    /**
     * Scope a query to only include schools with Junior Secondary level.
     */
    public function scopeWithJuniorSecondary($query)
    {
        return $query->where('has_junior_secondary', true);
    }

    /**
     * Scope a query to only include schools with Senior Secondary level.
     */
    public function scopeWithSeniorSecondary($query)
    {
        return $query->where('has_senior_secondary', true);
    }

    /**
     * Scope a query to only include schools with Secondary level (8-4-4).
     */
    public function scopeWithSecondary($query)
    {
        return $query->where('has_secondary', true);
    }

    /**
     * Get the curriculum levels offered by the school.
     */
    public function getCurriculumLevelsAttribute()
    {
        $levels = [];
        
        if ($this->has_pre_primary) $levels[] = 'Pre-Primary';
        if ($this->has_primary) $levels[] = 'Primary';
        if ($this->has_junior_secondary) $levels[] = 'Junior Secondary';
        if ($this->has_senior_secondary) $levels[] = 'Senior Secondary';
        if ($this->has_secondary) $levels[] = 'Secondary';
        
        return $levels;
    }

    /**
     * Check if the school offers CBC curriculum.
     */
    public function offersCbc()
    {
        return $this->primary_curriculum === 'CBC' || $this->secondary_curriculum === 'CBC';
    }

    /**
     * Check if the school offers 8-4-4 curriculum.
     */
    public function offersLegacy()
    {
        return $this->primary_curriculum === '8-4-4' || $this->secondary_curriculum === '8-4-4';
    }

    /**
     * Check if the school offers a specific level.
     */
    public function offersLevel($level)
    {
        $attribute = 'has_' . strtolower(str_replace(' ', '_', $level));
        return isset($this->$attribute) && $this->$attribute === true;
    }

    /**
     * Check if the school offers a specific Senior Secondary pathway.
     */
    public function offersPathway($pathway)
    {
        if (!$this->has_senior_secondary) {
            return false;
        }
        
        $pathways = $this->senior_secondary_pathways ?? [];
        return in_array($pathway, $pathways);
    }
}