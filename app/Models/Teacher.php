<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Teacher extends Model
{
    use HasFactory;
    
    public $timestamps = true;
    
    protected $fillable = [
        'user_id',
        'school_id',
        'qualification',
        'employment_type',
        'tsc_number',
        'specialization',          // e.g., 'Sciences', 'Languages', 'Mathematics'
        'curriculum_specialization', // 'CBC', '8-4-4', or 'Both'
        'max_subjects',            // Maximum number of subjects this teacher can handle
        'max_classes',             // Maximum number of classes this teacher can handle
    ];

    /**
     * Get the user associated with the teacher.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the school the teacher belongs to.
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The subjects that the teacher teaches.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class);
    }

    /**
     * Get the streams where this teacher is the class teacher.
     */
    public function classTeacherStreams()
    {
        return $this->hasMany(Stream::class, 'class_teacher_id');
    }

    /**
     * Get the streams where this teacher teaches.
     */
    public function teachingStreams()
    {
        return $this->belongsToMany(Stream::class);
    }

    /**
     * Scope a query to only include teachers who specialize in CBC.
     */
    public function scopeCbcSpecialists($query)
    {
        return $query->where('curriculum_specialization', 'CBC')
                    ->orWhere('curriculum_specialization', 'Both');
    }

    /**
     * Scope a query to only include teachers who specialize in 8-4-4.
     */
    public function scopeLegacySpecialists($query)
    {
        return $query->where('curriculum_specialization', '8-4-4')
                    ->orWhere('curriculum_specialization', 'Both');
    }

    /**
     * Scope a query to only include teachers with a specific specialization.
     */
    public function scopeBySpecialization($query, $specialization)
    {
        return $query->where('specialization', $specialization);
    }
}