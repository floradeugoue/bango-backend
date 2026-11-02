<?php

use App\Enums\OtpChannel;
use App\Models\Auth\Otp;
use App\Models\Listing\Listing;
use App\Models\Payment\LandlordPayout;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.zomloa.api_key' => 'fake-api-key',
        'services.zomloa.base_url' => 'https://api.zomloa.fake',
        'services.zomloa.sender_id' => 'BANGO',
    ]);

    Http::fake([
        '*/gateway/sms/send' => Http::response([
            'responsecode' => '200',
            'status' => 'QUEUED',
            'messageId' => 'msg-test-123',
        ], 200),
    ]);
});

test('GET /api/landlord/earnings returns collected, refunded, pending_payout, paid_out, currency, and movements', function () {
    $landlord = User::factory()->create();
    $prospect = User::factory()->create([
        'name' => 'Jean Prospect',
        'display_name' => 'Jean P.',
        'avatar_url' => 'https://example.com/avatar1.jpg',
    ]);

    $listing = Listing::create([
        'owner_id' => $landlord->id,
        'created_by_id' => $landlord->id,
        'title' => 'Appartement Akwa',
        'reference' => 'LST-AKWA-01',
        'kind' => 'flat',
        'mode' => 'rent',
        'price' => 200000,
        'currency' => 'XAF',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'status' => 'published',
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => Carbon::now()->addDays(2)->setHour(10)->setMinute(0),
        'end_at' => Carbon::now()->addDays(2)->setHour(10)->setMinute(30),
        'capacity' => 1,
        'status' => 'open',
    ]);

    // Visite 1 : Payée et non remboursée (5000 XAF)
    $visit1 = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $landlord->id,
        'status' => 'confirmed',
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now()->subDays(3),
        'payment_reference' => 'PAY-VIS-001',
    ]);

    // Visite 2 : Payée puis remboursée (5000 XAF)
    $visit2 = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $landlord->id,
        'status' => 'refunded',
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now()->subDays(4),
        'refunded_at' => now()->subDays(2),
        'refund_amount' => 5000,
        'payment_reference' => 'PAY-VIS-002',
    ]);

    // Payout effectué (completed) de 2000 XAF
    LandlordPayout::create([
        'reference' => 'PAYOUT-2026-001',
        'landlord_id' => $landlord->id,
        'amount' => 2000,
        'currency' => 'XAF',
        'status' => LandlordPayout::STATUS_COMPLETED,
        'provider' => 'monetbil',
        'channel' => 'orange_money',
        'external_reference' => 'OP-REF-12345',
        'scheduled_at' => now()->subDays(1),
        'executed_at' => now()->subDays(1),
    ]);

    Passport::actingAs($landlord);

    $res = $this->getJson('/api/landlord/earnings')
        ->assertStatus(200);

    $data = $res->json();

    expect((float) $data['collected'])->toBe(10000.0);
    expect((float) $data['refunded'])->toBe(5000.0);
    expect((float) $data['paid_out'])->toBe(2000.0);
    // Net restant à verser = 10000 - 5000 - 2000 = 3000
    expect((float) $data['pending_payout'])->toBe(3000.0);
    expect($data['currency'])->toBe('XAF');

    // Vérifier les mouvements
    $movements = collect($data['movements']);
    expect($movements->pluck('kind')->all())->toContain('visit', 'refund', 'payout');

    $visitMovement = $movements->firstWhere('kind', 'visit');
    expect((float) $visitMovement['amount'])->toBe(5000.0);
    expect($visitMovement['payer']['name'])->toBe('Jean P.');
    expect($visitMovement['payer']['avatar_url'])->toBe('https://example.com/avatar1.jpg');
});

test('PUT and GET /api/landlord/payout-method sets and retrieves payout method verified by OTP', function () {
    $landlord = User::factory()->create();
    Passport::actingAs($landlord);

    $phone = '+237690000001';

    // 1. Appel sans OTP -> envoie le code et renvoie status: otp_sent
    $resSend = $this->putJson('/api/landlord/payout-method', [
        'operator' => 'orange_money',
        'phone' => $phone,
    ])->assertStatus(200);

    expect($resSend->json('status'))->toBe('otp_sent');

    // Création / vérification de l'OTP en base
    Otp::updateOrCreate(
        ['identifier' => $phone, 'channel' => OtpChannel::Sms->value, 'context' => 'payout_method'],
        [
            'code' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]
    );

    // 2. Appel avec mauvais code OTP -> 422
    $this->putJson('/api/landlord/payout-method', [
        'operator' => 'orange_money',
        'phone' => $phone,
        'code' => '999999',
    ])->assertStatus(422);

    // 3. Appel avec bon code OTP -> 200 et enregistrement
    $resSuccess = $this->putJson('/api/landlord/payout-method', [
        'operator' => 'orange_money',
        'phone' => $phone,
        'code' => '123456',
    ])->assertStatus(200);

    expect($resSuccess->json('payout_method.operator'))->toBe('orange_money');
    expect($resSuccess->json('payout_method.phone'))->toBe($phone);
    expect($resSuccess->json('payout_method.verified_at'))->not->toBeNull();

    // 4. GET /api/landlord/payout-method pour relire
    $resGet = $this->getJson('/api/landlord/payout-method')
        ->assertStatus(200);

    expect($resGet->json('payout_method.operator'))->toBe('orange_money');
    expect($resGet->json('payout_method.phone'))->toBe($phone);
});

test('GET /api/landlord/payouts returns friday payouts with status and operator reference', function () {
    $landlord = User::factory()->create();

    // Payout 1 : programmé (scheduled)
    LandlordPayout::create([
        'reference' => 'PAYOUT-2026-F1',
        'landlord_id' => $landlord->id,
        'amount' => 15000,
        'currency' => 'XAF',
        'status' => LandlordPayout::STATUS_SCHEDULED,
        'provider' => 'monetbil',
        'channel' => 'mtn_momo',
        'external_reference' => null,
        'scheduled_at' => Carbon::now()->next(Carbon::FRIDAY),
    ]);

    // Payout 2 : versé (completed) avec référence opérateur
    LandlordPayout::create([
        'reference' => 'PAYOUT-2026-F2',
        'landlord_id' => $landlord->id,
        'amount' => 30000,
        'currency' => 'XAF',
        'status' => LandlordPayout::STATUS_COMPLETED,
        'provider' => 'monetbil',
        'channel' => 'orange_money',
        'external_reference' => 'OM-TRANS-987654',
        'scheduled_at' => Carbon::now()->previous(Carbon::FRIDAY),
        'executed_at' => Carbon::now()->previous(Carbon::FRIDAY)->setHour(12),
    ]);

    Passport::actingAs($landlord);

    $res = $this->getJson('/api/landlord/payouts')
        ->assertStatus(200);

    $payouts = $res->json('data');
    expect(count($payouts))->toBe(2);

    $completed = collect($payouts)->firstWhere('reference', 'PAYOUT-2026-F2');
    expect($completed['status'])->toBe('completed');
    expect($completed['operator_reference'])->toBe('OM-TRANS-987654');
    expect((float) $completed['amount'])->toBe(30000.0);

    $scheduled = collect($payouts)->firstWhere('reference', 'PAYOUT-2026-F1');
    expect($scheduled['status'])->toBe('scheduled');
    expect($scheduled['operator_reference'])->toBeNull();
});
