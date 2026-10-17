<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    use HasFactory;

    protected $table = 'admin_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'event',
        'ip_address',
        'user_agent',
        'context',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Helper pour journaliser un événement de sécurité sans exposer de secrets.
     *
     * @param  array<string, mixed>  $context
     */
    public static function logEvent(string $event, ?User $user = null, ?string $ip = null, ?string $userAgent = null, array $context = []): self
    {
        // Nettoyage strict des secrets avant stockage
        $sanitized = array_diff_key($context, array_flip([
            'password', 'password_confirmation', 'token', 'secret', 'two_factor_secret',
            'code', 'recovery_code', 'cookie', 'bango_admin_session', 'bango_admin_csrf',
        ]));

        return static::create([
            'user_id' => $user?->id,
            'event' => $event,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
            'context' => $sanitized,
            'created_at' => now(),
        ]);
    }
}
