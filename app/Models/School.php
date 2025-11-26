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
        'has_streams' // Whether the school has streams enabled
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'has_streams' => 'boolean',
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
}