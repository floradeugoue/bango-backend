<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminAuditLog extends Model
{
    use HasFactory;

    protected $table = 'admin_audit_logs';

    public $timestamps = false;

    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    protected $fillable = [
        'user_id',
        'event',
        'target_type',
        'target_id',
        'target_label',
        'ip_address',
        'user_agent',
        'context',
        'prev_hash',
        'hash',
        'is_tampered',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'is_tampered' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tamperAttempts(): HasMany
    {
        return $this->hasMany(AdminAuditTamperAttempt::class, 'audit_log_id');
    }

    /**
     * Calcule l'empreinte SHA-256 canonique selon la formule :
     * hash_n = SHA256(hash_{n-1} || id || timestamp || action || target || operator)
     */
    public static function computeHash(
        string $prevHash,
        int|string $id,
        string $timestamp,
        string $action,
        string $target,
        string $operator
    ): string {
        $canonicalPayload = "{$prevHash}|{$id}|{$timestamp}|{$action}|{$target}|{$operator}";

        return hash('sha256', $canonicalPayload);
    }

    /**
     * Vérifie la validité de l'empreinte de cette ligne.
     */
    public function verifyHash(): bool
    {
        if (empty($this->hash)) {
            return false;
        }

        $targetStr = $this->target_label ?? ($this->target_type ? "{$this->target_type}:{$this->target_id}" : 'system');
        $operatorStr = (string) ($this->user_id ?? 'system');
        $timestamp = $this->created_at ? $this->created_at->toIso8601String() : '';

        $expected = static::computeHash(
            prevHash: $this->prev_hash ?? self::GENESIS_PREV_HASH,
            id: $this->id,
            timestamp: $timestamp,
            action: $this->event,
            target: $targetStr,
            operator: $operatorStr
        );

        return hash_equals($expected, (string) $this->hash);
    }

    /**
     * Vérifie l'intégrité de la chaîne cryptographique jusqu'à une limite donnée.
     *
     * @return array{is_valid: bool, total_verified: int, broken_at_id: ?int, reason: ?string}
     */
    public static function verifyChain(int $limit = 1000): array
    {
        $logs = static::orderBy('id', 'asc')->limit($limit)->get();

        if ($logs->isEmpty()) {
            return [
                'is_valid' => true,
                'total_verified' => 0,
                'broken_at_id' => null,
                'reason' => null,
            ];
        }

        $previousHash = self::GENESIS_PREV_HASH;
        $total = 0;

        foreach ($logs as $index => $log) {
            $total++;

            // 1. Vérification de la continuité du chaînage avec le bloc précédent
            $expectedPrevHash = ($index === 0 && empty($log->prev_hash)) ? self::GENESIS_PREV_HASH : $previousHash;
            $currentPrevHash = $log->prev_hash ?? self::GENESIS_PREV_HASH;

            if ($currentPrevHash !== $expectedPrevHash) {
                return [
                    'is_valid' => false,
                    'total_verified' => $total,
                    'broken_at_id' => $log->id,
                    'reason' => "Rupture de chaîne au log #{$log->id}: prev_hash attendu [{$expectedPrevHash}] mais trouvé [{$currentPrevHash}].",
                ];
            }

            // 2. Vérification de l'empreinte du bloc lui-même (intégrité des données)
            if (! $log->verifyHash()) {
                return [
                    'is_valid' => false,
                    'total_verified' => $total,
                    'broken_at_id' => $log->id,
                    'reason' => "Altération détectée au log #{$log->id}: le contenu ne correspond plus à l'empreinte SHA-256 scellée.",
                ];
            }

            $previousHash = $log->hash;
        }

        return [
            'is_valid' => true,
            'total_verified' => $total,
            'broken_at_id' => null,
            'reason' => null,
        ];
    }

    /**
     * Enregistre un événement chaîné cryptographiquement dans une transaction avec verrou.
     *
     * @param  array<string, mixed>  $context
     */
    public static function logChainedEvent(
        string $event,
        ?User $operator = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $targetLabel = null,
        array $context = [],
        ?string $ip = null,
        ?string $userAgent = null
    ): self {
        return DB::transaction(function () use ($event, $operator, $targetType, $targetId, $targetLabel, $context, $ip, $userAgent) {
            // Verrou sur la table d'audit pour garantir une séquence sérialisée sans bifurcation concurrente
            $lastLog = static::orderBy('id', 'desc')->lockForUpdate()->first();
            $prevHash = $lastLog?->hash ?? self::GENESIS_PREV_HASH;

            $sanitized = array_diff_key($context, array_flip([
                'password', 'password_confirmation', 'token', 'secret', 'two_factor_secret',
                'code', 'recovery_code', 'cookie', 'bango_admin_session', 'bango_admin_csrf',
            ]));

            $createdAt = Carbon::now();
            $targetStr = $targetLabel ?? ($targetType ? "{$targetType}:{$targetId}" : 'system');
            $operatorStr = (string) ($operator?->id ?? 'system');

            $log = new static;
            $log->user_id = $operator?->id;
            $log->event = $event;
            $log->target_type = $targetType;
            $log->target_id = $targetId;
            $log->target_label = $targetLabel;
            $log->ip_address = $ip;
            $log->user_agent = $userAgent ? substr($userAgent, 0, 500) : null;
            $log->context = $sanitized;
            $log->prev_hash = $prevHash;
            $log->created_at = $createdAt;
            $log->save();

            $log->hash = static::computeHash(
                prevHash: $prevHash,
                id: $log->id,
                timestamp: $createdAt->toIso8601String(),
                action: $event,
                target: $targetStr,
                operator: $operatorStr
            );
            $log->save();

            return $log;
        });
    }

    /**
     * Helper rétrocompatible conservant le comportement standard tout en renseignant le chaînage.
     *
     * @param  array<string, mixed>  $context
     */
    public static function logEvent(string $event, ?User $user = null, ?string $ip = null, ?string $userAgent = null, array $context = []): self
    {
        $targetType = $context['target_type'] ?? null;
        $targetId = isset($context['target_id']) ? (string) $context['target_id'] : null;
        $targetLabel = $context['target_label'] ?? $context['target'] ?? null;

        return static::logChainedEvent(
            event: $event,
            operator: $user,
            targetType: $targetType,
            targetId: $targetId,
            targetLabel: $targetLabel,
            context: $context,
            ip: $ip,
            userAgent: $userAgent
        );
    }
}
