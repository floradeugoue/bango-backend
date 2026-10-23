<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Mail\Payment\PaymentReceiptMail;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('rejects unauthenticated requests on receipt download endpoint', function () {
    getJson('/api/payments/1/receipt')->assertStatus(401);
});

it('returns 404 if transaction does not exist', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    getJson('/api/payments/999999/receipt')->assertStatus(404);
});

it('forbids downloading a receipt of another user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $transaction = PaymentTransaction::create([
        'reference' => 'TX-TEST-USER1',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user1->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'fee_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 5100,
        'amount_charged' => 5100,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
    ]);

    Passport::actingAs($user2);

    getJson("/api/payments/{$transaction->id}/receipt")->assertStatus(403);
});

it('refuses to generate receipt for a pending or failed transaction', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $pendingTx = PaymentTransaction::create([
        'reference' => 'TX-TEST-PENDING',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 15000,
        'fee_amount' => 300,
        'tax_amount' => 0,
        'total_amount' => 15300,
        'amount_charged' => 15300,
        'status' => PaymentStatus::Pending,
    ]);

    $failedTx = PaymentTransaction::create([
        'reference' => 'TX-TEST-FAILED',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 10000,
        'fee_amount' => 200,
        'tax_amount' => 0,
        'total_amount' => 10200,
        'amount_charged' => 10200,
        'status' => PaymentStatus::Failed,
        'failed_at' => now(),
    ]);

    getJson("/api/payments/{$pendingTx->id}/receipt")->assertStatus(422);
    getJson("/api/payments/{$failedTx->id}/receipt")->assertStatus(422);
});

it('generates a valid binary PDF receipt for a confirmed visit payment', function () {
    $user = User::factory()->create(['name' => 'Jean Dupont', 'email' => 'jean.dupont@example.com']);
    Passport::actingAs($user);

    $transaction = PaymentTransaction::create([
        'reference' => 'TX-VISIT-001',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'fee_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 5100,
        'amount_charged' => 5100,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
        'external_reference' => 'OP-123456789',
    ]);

    $response = get("/api/payments/{$transaction->id}/receipt");

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('recu-TX-VISIT-001.pdf');

    $content = $response->getContent();
    expect($content)->toStartWith('%PDF-1.4');
    expect($content)->toContain('TX-VISIT-001');
    expect($content)->toContain('BANGO');
});

it('generates a valid binary PDF receipt for subscription and boost transactions', function () {
    $user = User::factory()->create(['name' => 'Marie Curie', 'email' => 'marie.curie@example.com']);
    Passport::actingAs($user);

    $subTx = PaymentTransaction::create([
        'reference' => 'TX-SUB-777',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 25000,
        'fee_amount' => 500,
        'tax_amount' => 0,
        'total_amount' => 25500,
        'amount_charged' => 25500,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
    ]);

    $boostTx = PaymentTransaction::create([
        'reference' => 'TX-BOOST-888',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 12000,
        'fee_amount' => 240,
        'tax_amount' => 0,
        'total_amount' => 12240,
        'amount_charged' => 12240,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
    ]);

    // Lookup by id
    $subResponse = get("/api/payments/{$subTx->id}/receipt");
    $subResponse->assertStatus(200);
    expect($subResponse->getContent())->toStartWith('%PDF-1.4');

    // Lookup by reference string
    $boostResponse = get("/api/payments/{$boostTx->reference}/receipt");
    $boostResponse->assertStatus(200);
    expect($boostResponse->getContent())->toStartWith('%PDF-1.4');
});

it('sends receipt PDF by email to user verified email address or custom provided email (PAY-23)', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'client@example.com',
        'email_verified_at' => now(),
    ]);

    $transaction = PaymentTransaction::create([
        'reference' => 'TX-CONFIRMED-EMAIL',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'fee_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 5100,
        'amount_charged' => 5100,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
    ]);

    Passport::actingAs($user);

    // 1. Envoi à l'adresse du compte connecté par défaut
    $response = $this->postJson("/api/payments/{$transaction->id}/receipt/email");
    $response->assertStatus(202)
        ->assertJson([
            'success' => true,
            'recipient' => 'client@example.com',
        ]);

    Mail::assertSent(PaymentReceiptMail::class, function ($mail) {
        return $mail->hasTo('client@example.com');
    });

    // 2. Envoi à une adresse personnalisée fournie dans le corps
    $customResponse = $this->postJson("/api/payments/{$transaction->id}/receipt/email", [
        'email' => 'comptabilite@societe.com',
    ]);
    $customResponse->assertStatus(202)
        ->assertJson([
            'success' => true,
            'recipient' => 'comptabilite@societe.com',
        ]);

    Mail::assertSent(PaymentReceiptMail::class, function ($mail) {
        return $mail->hasTo('comptabilite@societe.com');
    });
});

it('returns 404 when sending receipt email for a payment belonging to another user', function () {
    Mail::fake();

    $user1 = User::factory()->create(['email' => 'user1@example.com']);
    $user2 = User::factory()->create(['email' => 'user2@example.com']);

    $transaction = PaymentTransaction::create([
        'reference' => 'TX-OTHER-USER',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user1->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'fee_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 5100,
        'amount_charged' => 5100,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
    ]);

    Passport::actingAs($user2);

    $this->postJson("/api/payments/{$transaction->id}/receipt/email")
        ->assertStatus(404);

    Mail::assertNothingSent();
});

it('returns 422 when sending receipt email if user account email is unverified or empty', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email' => 'unverified@example.com',
        'email_verified_at' => null,
    ]);

    $transaction = PaymentTransaction::create([
        'reference' => 'TX-NO-EMAIL',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'fee_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 5100,
        'amount_charged' => 5100,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
    ]);

    Passport::actingAs($user);

    $this->postJson("/api/payments/{$transaction->id}/receipt/email")
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['email']]);

    Mail::assertNothingSent();

    // Mais si une adresse email explicite valide est fournie, cela passe
    $this->postJson("/api/payments/{$transaction->id}/receipt/email", ['email' => 'autre@domaine.com'])
        ->assertStatus(202);

    Mail::assertSent(PaymentReceiptMail::class);
});
