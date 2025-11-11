<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; // ✅ Import HasFactory
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stream extends Model
{
     // ✅ Enable factory usage

    protected $fillable = [
        'school_id',
        'class_id',
        'name',
    ];

    /**
     * Get the school that owns this stream.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /**
     * Get the classroom that this stream belongs to.
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class, 'class_id');
    }
}
