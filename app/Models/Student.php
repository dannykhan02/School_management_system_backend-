<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'school_id',
        'class_id',
        'parent_id',
        'status',
        'admission_number',
        'date_of_birth',
        'gender',
        'admission_date',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function parent(): BelongsTo
    {
        
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'class_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
    }

    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class);
    }
}
