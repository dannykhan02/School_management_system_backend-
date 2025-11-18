<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    use HasFactory;
    
    public $timestamps = true; // Add this line if not present
    
    protected $fillable = [
        'user_id',
        'school_id',
        'qualification',
        'employment_type',
        'tsc_number',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    // The classrooms relationship has been removed since class_teacher_id column no longer exists
    // If you need to track which classrooms a teacher is associated with, you would need to
    // create a pivot table or add a different relationship structure
    
    public function subjects()
    {
        return $this->belongsToMany(Subject::class);
    }
    
    // If you need to get the streams where this teacher is the class teacher:
    public function classTeacherStreams()
    {
        return $this->hasMany(Stream::class, 'class_teacher_id');
    }
    
    // If you need to get the streams where this teacher is part of the teaching staff:
    public function teachingStreams()
    {
        return $this->belongsToMany(Stream::class);
    }
}