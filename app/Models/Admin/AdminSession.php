<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSession extends Model
{
    use HasFactory;

    protected $table = 'admin_sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'csrf_token',
        'ip_address',
        'user_agent',
        'two_factor_verified_at',
        'last_activity_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'two_factor_verified_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Vérifie si la session est expirée (inactivité glissante ou durée maximale absolue).
     */
    public function isExpired(int $slidingMinutes = 30): bool
    {
        if ($this->expires_at->isPast()) {
            return true;
        }

        return $this->last_activity_at->copy()->addMinutes($slidingMinutes)->isPast();
    }

    /**
     * Vérifie si la session a été révoquée.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Actualise la dernière activité (session glissante).
     */
    public function touchActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }

    /**
     * Révoque immédiatement la session.
     */
    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }
}
