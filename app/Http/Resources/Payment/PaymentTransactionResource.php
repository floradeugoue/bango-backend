<?php

namespace App\Http\Resources\Payment;

use App\Enums\Payment\PaymentServiceType;
use App\Models\Payment\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentTransaction
 */
class PaymentTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestAttempt = $this->attempts()->latest('attempt_number')->first();

        // Masquage partiel du numéro de téléphone (RGPD / Confidentialité)
        $rawPhone = $latestAttempt?->metadata['phone'] ?? $this->metadata['phone'] ?? null;
        $maskedPhone = null;
        if (! empty($rawPhone)) {
            $len = strlen($rawPhone);
            $maskedPhone = $len > 6
                ? substr($rawPhone, 0, 4).'***'.substr($rawPhone, -2)
                : '***';
        }

        $kind = match ($this->service_type) {
            PaymentServiceType::Subscription => 'plan',
            PaymentServiceType::Certification => 'certification',
            PaymentServiceType::Boost => 'boost',
            PaymentServiceType::Visit => 'visit',
            PaymentServiceType::Credit => 'credit',
        };

        $label = $this->product?->name
            ?? $this->metadata['pricing_snapshot']['product_name']
            ?? $this->service_type->label();

        return [
            'id' => (string) $this->id,
            'reference' => $this->reference,
            'kind' => $kind,
            'service_type' => $this->service_type->value,
            'channel' => $this->channel->value,
            'provider' => $this->metadata['operator'] ?? $this->metadata['provider'] ?? $this->provider->value,
            'label' => $label,
            'amount' => (float) $this->amount,
            'fee' => (float) $this->fee_amount,
            'tax' => (float) $this->tax_amount,
            'totalAmount' => (float) $this->total_amount,
            'total_amount' => (float) $this->total_amount,
            'creditUsed' => (float) $this->credit_used,
            'charged' => (float) $this->amount_charged,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'method' => [
                'operator' => $latestAttempt?->metadata['operator'] ?? $this->provider->value,
                'label' => $this->channel->label(),
                'phone' => $maskedPhone,
            ],
            'operatorReference' => $this->metadata['operator_reference']
                ?? $latestAttempt?->external_reference
                ?? $this->external_reference,
            'operatorCode' => $latestAttempt?->error_code,
            'at' => $this->created_at?->toIso8601String(),
            'confirmedAt' => $this->confirmed_at?->toIso8601String(),
            'subscription_id' => $this->subscription_id,
        ];
    }
}
