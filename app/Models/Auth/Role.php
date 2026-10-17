<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
