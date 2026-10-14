<?php

namespace App\Models\Payment;

use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentStatus;
use BadMethodCallException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    use HasFactory;

    protected $table = 'payment_events';

    // Journal immuable append-only : aucun champ updated_at
    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'event_type',
        'source',
        'from_status',
        'to_status',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => PaymentEventSource::class,
            'from_status' => PaymentStatus::class,
            'to_status' => PaymentStatus::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    /**
     * Empêche toute modification applicative (Immuabilité).
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new BadMethodCallException('Les événements financiers de paiement sont immuables et ne peuvent pas être modifiés.');
    }

    /**
     * Empêche toute suppression applicative (Immuabilité).
     */
    public function delete(): ?bool
    {
        throw new BadMethodCallException('Les événements financiers de paiement sont immuables et ne peuvent pas être supprimés.');
    }
}
