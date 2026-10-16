<?php

use App\Enums\Boost\CampaignStatus;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Boost\Campaign;
use App\Models\Payment\PaymentTransaction;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Payment\PaymentEngineService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create(['name' => 'Ibrahim Promoteur']);
    $this->paymentEngine = app(PaymentEngineService::class);
});

test('La confirmation d\'une transaction de paiement externe active automatiquement la campagne rattachée', function () {
    $post = Post::factory()->create(['user_id' => $this->user->id]);

    // 1. Création d'une campagne en attente de paiement externe
    $campaign = Campaign::factory()->pending()->create([
        'user_id' => $this->user->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'budget_amount' => '10000.00',
        'duration_days' => 5,
        'status' => CampaignStatus::Pending,
    ]);

    expect($campaign->status)->toBe(CampaignStatus::Pending)
        ->and($campaign->start_at)->toBeNull();

    // 2. Création de la transaction de paiement externe (PAY) rattachée à la campagne
    /** @var PaymentTransaction $transaction */
    $transaction = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Boost,
        'payable_type' => Campaign::class,
        'payable_id' => (string) $campaign->id,
        'amount' => '10000.00',
        'currency' => 'XAF',
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'idempotency_key' => 'idemp_boost_tx_1',
        'status' => PaymentStatus::Pending,
    ]);

    // Renseigner la référence dans la campagne
    $campaign->update(['payment_reference' => $transaction->reference]);

    // 3. Confirmation du paiement via le moteur de paiement (webhook / callback)
    $confirmedTx = $this->paymentEngine->confirmAndFulfill(
        transaction: $transaction,
        externalReference: 'EXT-OM-998877'
    );

    expect($confirmedTx->status)->toBe(PaymentStatus::Confirmed);

    // 4. Vérifier que la campagne est passée à Active avec ses dates de début et fin
    $freshCampaign = $campaign->fresh();
    expect($freshCampaign->status)->toBe(CampaignStatus::Active)
        ->and($freshCampaign->payment_method)->toBe('external')
        ->and($freshCampaign->payment_reference)->toBe($transaction->reference)
        ->and($freshCampaign->start_at)->not->toBeNull()
        ->and($freshCampaign->end_at)->not->toBeNull();
});

test('Un rejeu de confirmation de paiement (idempotence webhook) n\'altère pas la campagne déjà activée', function () {
    $post = Post::factory()->create(['user_id' => $this->user->id]);

    $campaign = Campaign::factory()->pending()->create([
        'user_id' => $this->user->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'budget_amount' => '5000.00',
        'duration_days' => 3,
    ]);

    $transaction = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Boost,
        'payable_type' => Campaign::class,
        'payable_id' => (string) $campaign->id,
        'amount' => '5000.00',
        'currency' => 'XAF',
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'idempotency_key' => 'idemp_boost_tx_2',
        'status' => PaymentStatus::Pending,
    ]);

    // Première confirmation
    $this->paymentEngine->confirmAndFulfill($transaction, 'EXT-1');
    $firstStartAt = $campaign->fresh()->start_at;

    // Deuxième confirmation (webhook renvoyé par l'agrégateur)
    $this->paymentEngine->confirmAndFulfill($transaction->fresh(), 'EXT-1');

    // La campagne doit rester active et la date ne doit pas avoir changé
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and($campaign->fresh()->start_at->toIso8601String())->toBe($firstStartAt->toIso8601String());
});

test('POST /api/payments/quote permet d\'obtenir un devis certifié pour une prestation de boost', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/payments/quote', [
        'kind' => 'boost',
        'gateway' => 'monetbil',
        'channel' => 'mobile_money',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.currency', 'XAF')
        ->assertJsonPath('data.base_amount', 2180);
});
