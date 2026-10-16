<?php

namespace Database\Factories\Boost;

use App\Models\Boost\CreditWallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditWallet>
 */
class CreditWalletFactory extends Factory
{
    protected $model = CreditWallet::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'balance' => '10000.00',
            'currency' => 'XAF',
        ];
    }

    public function withBalance(float|string $balance): static
    {
        return $this->state(fn () => [
            'balance' => number_format((float) $balance, 2, '.', ''),
        ]);
    }
}
