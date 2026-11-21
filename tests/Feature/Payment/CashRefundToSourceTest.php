<?php

use App\Models\User;
use App\Services\Boost\CreditWalletService;
use App\Services\Payment\Gateways\MonetbilGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

afterEach(fn () => MonetbilGateway::resetFakes());

it('refunds the credit balance in cash to the original payment method', function () {
    $disbursed = [];
    MonetbilGateway::fakeDisburse(function ($phone, $amount, $currency, $reference, $operator) use (&$disbursed) {
        $disbursed = compact('phone', 'amount');

        return [
            'success' => true,
            'external_reference' => 'EXT-1',
            'status' => 'completed',
            'phone' => $phone,
            'operator' => $operator,
            'amount' => $amount,
            'currency' => $currency,
        ];
    });

    $user = User::factory()->create(['phone' => '+237699112233']);
    app(CreditWalletService::class)->credit($user, 3000);
    Passport::actingAs($user);

    $this->postJson('/api/credits/wallet/refund', ['phone' => '237699112233'])
        ->assertOk()
        ->assertJsonPath('data.amount', 3000)
        ->assertJsonPath('data.refund_channel', 'original_payment_method');

    expect($disbursed['amount'])->toBe(3000.0)
        ->and(app(CreditWalletService::class)->getBalance($user))->toBe(0.0);
});

it('rejects a cash refund when the credit balance is empty', function () {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/credits/wallet/refund')->assertStatus(422);
});
