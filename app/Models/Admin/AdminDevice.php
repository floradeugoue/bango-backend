<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminDevice extends Model
{
    use HasFactory;

    protected $table = 'admin_devices';

    protected $fillable = [
        'user_id',
        'device_hash',
        'ip_address',
        'user_agent',
        'is_trusted',
        'verification_code',
        'verification_expires_at',
        'reported_suspicious_at',
        'suspicion_reason',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_trusted' => 'boolean',
            'verification_expires_at' => 'datetime',
            'reported_suspicious_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
