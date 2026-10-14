<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePurchaseReceipt extends Model
{
    use HasFactory;

    protected $table = 'store_purchase_receipts';

    protected $fillable = [
        'transaction_id',
        'provider',
        'store_product_id',
        'original_transaction_id',
        'purchase_token_hash',
        'verification_status',
        'environment',
        'store_payload',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'store_payload' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    /**
     * Calcule le hash SHA-256 standardisé d'un reçu ou purchase token pour la protection anti-rejeu.
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', trim($rawToken));
    }
}
