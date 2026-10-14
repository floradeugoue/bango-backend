<?php

namespace Database\Factories\Payment;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        $amount = 7500.00;
        $fee = 0.00;
        $tax = 0.00;
        $total = PaymentTransaction::calculateTotal($amount, $fee, $tax);

        return [
            'reference' => PaymentTransaction::generateReference(),
            'user_id' => User::factory(),
            'service_type' => PaymentServiceType::Subscription,
            'provider' => PaymentProvider::Monetbil,
            'channel' => PaymentChannel::MobileMoney,
            'currency' => 'XAF',
            'amount' => $amount,
            'fee_amount' => $fee,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'credit_used' => 0.00,
            'amount_charged' => $total,
            'status' => PaymentStatus::Pending,
            'idempotency_key' => 'idemp_'.Str::uuid(),
            'metadata' => ['device' => 'expo_mobile'],
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Confirmed,
            'confirmed_at' => now(),
            'external_reference' => 'MB_'.Str::random(12),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Failed,
            'failed_at' => now(),
        ]);
    }
}
