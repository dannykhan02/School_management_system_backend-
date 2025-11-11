<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    

    protected $fillable = ['name', 'address', 'school_type', 'city', 'code', 'phone', 'email', 'logo'];

    public function academicYears(): HasMany
    {
        return $this->hasMany(AcademicYear::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function parents(): HasMany
    {
        return $this->hasMany(ParentModel::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }
}