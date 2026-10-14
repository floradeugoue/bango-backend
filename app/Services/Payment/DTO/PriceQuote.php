<?php

namespace App\Services\Payment\DTO;

use App\Enums\Payment\PaymentServiceType;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;

readonly class PriceQuote
{
    /**
     * @param  array<string, mixed>  $breakdown
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public PaymentServiceType $serviceType,
        public ?SubscriptionProduct $product,
        public ?SubscriptionPrice $price,
        public string $currency,
        public string $amount,
        public string $feeAmount,
        public string $taxAmount,
        public string $totalAmount,
        public string $gateway,
        public array $breakdown,
        public array $snapshot
    ) {}

    /**
     * Convertit le devis en tableau formaté pour l'API client mobile.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_type' => $this->serviceType->value,
            'product_name' => $this->product?->name,
            'price_id' => $this->price?->id,
            'currency' => $this->currency,
            'amount' => (float) $this->amount,
            'fee_amount' => (float) $this->feeAmount,
            'tax_amount' => (float) $this->taxAmount,
            'total_amount' => (float) $this->totalAmount,
            'gateway' => $this->gateway,
            'breakdown' => $this->breakdown,
        ];
    }
}
