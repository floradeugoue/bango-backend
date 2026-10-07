<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $fillable = [
        'identifier',
        'code',
        'attempts',
        'expires_at',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }
}
