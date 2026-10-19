<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminChallenge extends Model
{
    use HasFactory;

    protected $table = 'admin_challenges';

    protected $fillable = [
        'user_id',
        'token',
        'type',
        'attempts',
        'ip_address',
        'user_agent',
        'expires_at',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed() && $this->attempts < 5;
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }

    public function incrementAttempts(): int
    {
        $this->increment('attempts');

        return (int) $this->fresh()->attempts;
    }
}
