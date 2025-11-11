<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = ['school_id', 'name', 'code'];

    /**
     * Get the school that owns the subject.
     */
    public function school()
    {
        return $this->belongsTo(School::class);
    }

    /**
     * The teachers that teach the subject.
     */
    public function teachers()
    {
        return $this->belongsToMany(Teacher::class);
    }
}