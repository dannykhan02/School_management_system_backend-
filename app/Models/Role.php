<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    // Explicitly tell Laravel which table to use
    protected $table = 'roles';

    // Columns allowed for mass assignment
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * A role can have many users.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }
}
