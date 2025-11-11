<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Classroom extends Model
{
    

    protected $table = 'classes';
    
    protected $fillable = ['school_id', 'class_name', 'class_teacher_id', 'capacity'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    // ✅ Make teacher relationship safe for nullable class_teacher_id
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'class_teacher_id')->withDefault();
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'class_id');
    }

    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class, 'class_id');
    }

    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class, 'class_id');
    }
}
