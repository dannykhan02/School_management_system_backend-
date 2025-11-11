<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParentModel extends Model
{
    protected $table = 'parents';
    
    protected $fillable = ['user_id', 'school_id', 'relation'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'parent_id');
    }
}