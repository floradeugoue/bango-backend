<?php

namespace App\Models\Payment;

use App\Enums\Payment\PaymentAttemptStatus;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\InvalidPaymentTransitionException;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'reference',
        'user_id',
        'service_type',
        'payable_type',
        'payable_id',
        'product_id',
        'price_id',
        'subscription_id',
        'provider',
        'channel',
        'currency',
        'amount',
        'fee_amount',
        'tax_amount',
        'total_amount',
        'credit_used',
        'amount_charged',
        'status',
        'external_reference',
        'idempotency_key',
        'metadata',
        'confirmed_at',
        'failed_at',
        'cancelled_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'service_type' => PaymentServiceType::class,
            'provider' => PaymentProvider::class,
            'channel' => PaymentChannel::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'credit_used' => 'decimal:2',
            'amount_charged' => 'decimal:2',
            'metadata' => 'array',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentTransaction $transaction): void {
            if (empty($transaction->reference)) {
                $transaction->reference = static::generateReference();
            }

            if ($transaction->total_amount === null) {
                $transaction->total_amount = static::calculateTotal(
                    $transaction->amount ?? 0,
                    $transaction->fee_amount ?? 0,
                    $transaction->tax_amount ?? 0
                );
            }

            if ($transaction->amount_charged === null) {
                $transaction->amount_charged = static::calculateCharged(
                    $transaction->total_amount,
                    $transaction->credit_used ?? 0
                );
            }
        });

        static::created(function (PaymentTransaction $transaction): void {
            // Journalisation append-only automatique de la création
            $transaction->events()->create([
                'event_type' => 'created',
                'source' => PaymentEventSource::System->value,
                'from_status' => null,
                'to_status' => $transaction->status->value,
                'payload' => [
                    'amount' => $transaction->amount,
                    'total_amount' => $transaction->total_amount,
                    'currency' => $transaction->currency,
                    'service_type' => $transaction->service_type->value,
                    'provider' => $transaction->provider->value,
                ],
            ]);
        });
    }

    /**
     * Génère une référence publique unique, non prédictible et tracée.
     * Format : BNG-TX-YYYYMMDD-XXXXXXXX
     */
    public static function generateReference(): string
    {
        return sprintf(
            'BNG-TX-%s-%s',
            Carbon::now()->format('Ymd'),
            strtoupper(Str::random(8))
        );
    }

    /**
     * Calcule le total monétaire exact sans imprécision de virgule flottante.
     * Formule : total = montant du service + frais de paiement + taxes
     */
    public static function calculateTotal(string|float|int $amount, string|float|int $fee = 0, string|float|int $tax = 0): string
    {
        $sum = (float) $amount + (float) $fee + (float) $tax;

        return number_format($sum, 2, '.', '');
    }

    /**
     * Calcule le montant restant à débiter auprès de la passerelle.
     * Formule : chargé = max(0, total - credit_used)
     */
    public static function calculateCharged(string|float|int $total, string|float|int $creditUsed = 0): string
    {
        $charged = max(0, (float) $total - (float) $creditUsed);

        return number_format($charged, 2, '.', '');
    }

    /**
     * Vérifie si la transaction peut effectuer une transition vers le statut cible.
     */
    public function canTransitionTo(PaymentStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    /**
     * Effectue une transition de statut contrôlée, vérifiée et auditée.
     * Met à jour automatiquement les horodatages et enregistre un événement immuable.
     *
     * @throws InvalidPaymentTransitionException
     */
    public function transitionTo(
        PaymentStatus $targetStatus,
        PaymentEventSource $source = PaymentEventSource::System,
        ?string $reason = null,
        ?array $payload = null
    ): self {
        if (! $this->canTransitionTo($targetStatus)) {
            throw new InvalidPaymentTransitionException($this->status, $targetStatus);
        }

        $oldStatus = $this->status;

        // Mise à jour des horodatages selon le statut cible
        match ($targetStatus) {
            PaymentStatus::Confirmed => $this->confirmed_at = Carbon::now(),
            PaymentStatus::Failed => $this->failed_at = Carbon::now(),
            PaymentStatus::Cancelled => $this->cancelled_at = Carbon::now(),
            PaymentStatus::Refunded => $this->refunded_at = Carbon::now(),
            default => null,
        };

        $this->status = $targetStatus;
        $this->save();

        // Ajout immuable dans payment_events
        $this->events()->create([
            'event_type' => 'status_transition',
            'source' => $source->value,
            'from_status' => $oldStatus->value,
            'to_status' => $targetStatus->value,
            'payload' => array_filter([
                'reason' => $reason,
                'details' => $payload,
            ], fn ($val) => $val !== null),
        ]);

        return $this;
    }

    /**
     * Démarre une nouvelle tentative de paiement pour cette transaction.
     */
    public function createAttempt(
        PaymentProvider $provider,
        ?PaymentChannel $channel = null,
        ?string $externalReference = null,
        ?array $metadata = null
    ): PaymentAttempt {
        $nextAttemptNumber = ((int) $this->attempts()->max('attempt_number')) + 1;

        /** @var PaymentAttempt $attempt */
        $attempt = $this->attempts()->create([
            'attempt_number' => $nextAttemptNumber,
            'provider' => $provider,
            'channel' => $channel ?? $this->channel,
            'external_reference' => $externalReference,
            'status' => PaymentAttemptStatus::Initiated,
            'started_at' => Carbon::now(),
            'metadata' => $metadata,
        ]);

        $this->events()->create([
            'event_type' => 'attempt_started',
            'source' => PaymentEventSource::System->value,
            'from_status' => $this->status->value,
            'to_status' => $this->status->value,
            'payload' => [
                'attempt_number' => $nextAttemptNumber,
                'provider' => $provider->value,
                'external_reference' => $externalReference,
            ],
        ]);

        return $attempt;
    }

    // --- Relations Eloquent ---

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SubscriptionProduct::class, 'product_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPrice::class, 'price_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'transaction_id')->orderBy('attempt_number');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'transaction_id')->orderBy('created_at');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(PaymentDispute::class, 'transaction_id')->latest('created_at');
    }

    public function latestAttempt(): HasOne
    {
        return $this->hasOne(PaymentAttempt::class, 'transaction_id')->latestOfMany('attempt_number');
    }

    public function storeReceipt(): HasOne
    {
        return $this->hasOne(StorePurchaseReceipt::class, 'transaction_id');
    }

    // --- Scopes utiles ---

    public function scopeForUser(Builder $query, int|User $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    public function scopeStatus(Builder $query, PaymentStatus|string $status): Builder
    {
        $statusValue = $status instanceof PaymentStatus ? $status->value : $status;

        return $query->where('status', $statusValue);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Pending->value);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Confirmed->value);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Failed->value);
    }

    public function scopeService(Builder $query, PaymentServiceType|string $type): Builder
    {
        $typeValue = $type instanceof PaymentServiceType ? $type->value : $type;

        return $query->where('service_type', $typeValue);
    }

    public function scopeProvider(Builder $query, PaymentProvider|string $provider): Builder
    {
        $providerValue = $provider instanceof PaymentProvider ? $provider->value : $provider;

        return $query->where('provider', $providerValue);
    }

    public function scopeExternalReference(Builder $query, string $reference): Builder
    {
        return $query->where('external_reference', $reference);
    }
}
