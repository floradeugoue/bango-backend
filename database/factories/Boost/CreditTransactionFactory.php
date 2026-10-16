<?php

namespace Database\Factories\Boost;

use App\Enums\Boost\CreditTransactionType;
use App\Models\Boost\CreditTransaction;
use App\Models\Boost\CreditWallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    protected $model = CreditTransaction::class;

    public function definition(): array
    {
        $wallet = CreditWallet::factory();

        return [
            'wallet_id' => $wallet,
            'user_id' => User::factory(),
            'type' => CreditTransactionType::Deposit,
            'amount' => '5000.00',
            'balance_before' => '0.00',
            'balance_after' => '5000.00',
            'reference_type' => null,
            'reference_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'description' => 'Dépôt de test',
            'metadata' => null,
            'created_at' => now(),
        ];
    }
}
