<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    protected $fillable = ['school_id', 'year', 'term', 'start_date', 'end_date'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function studentClasses(): HasMany
    {
        return $this->hasMany(StudentClass::class);
    }
}