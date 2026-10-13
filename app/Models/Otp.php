<?php

namespace App\Models;

use App\Enums\OtpChannel;
use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $fillable = [
        'identifier',
        'channel',
        'context',
        'code',
        'attempts',
        'expires_at',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }
}
