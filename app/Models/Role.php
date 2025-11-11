<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model  // Make sure class name is Role (singular)
{
    protected $fillable = ['name'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
