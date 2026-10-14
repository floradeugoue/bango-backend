<?php

namespace App\Models\Subscription;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionReminder extends Model
{
    use HasFactory;

    protected $table = 'subscription_reminders';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'reminder_type',
        'target_date',
        'channel',
        'status',
        'idempotency_key',
        'payload',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'datetime',
            'sent_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Génère une clé d'idempotence standardisée pour un rappel d'échéance.
     */
    public static function makeUpcomingKey(int $subscriptionId, string $reminderType, string $periodEndDate): string
    {
        return hash('sha256', "rem_upcoming_{$subscriptionId}_{$reminderType}_{$periodEndDate}");
    }

    /**
     * Génère une clé d'idempotence standardisée pour une relance après échec de paiement.
     */
    public static function makeFailedPaymentKey(int $subscriptionId, int $transactionId, string $reminderType): string
    {
        return hash('sha256', "rem_failed_{$subscriptionId}_{$transactionId}_{$reminderType}");
    }
}
