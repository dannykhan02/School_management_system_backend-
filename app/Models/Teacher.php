<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    

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

    public function classrooms()
    {
        return $this->hasMany(Classroom::class, 'class_teacher_id');
    }

    /**
     * The subjects that the teacher teaches.
     */
    public function subjects()
    {
        return $this->belongsToMany(Subject::class);
    }
}