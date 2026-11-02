<?php

use App\Enums\Payment\PaymentEventSource;
use App\Models\Auth\Role;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Payment\PaymentEngineService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);
    Storage::fake('s3');
});

it('accepts requested_badge in /landlord/kyc/submit and displays it in admin KYC queue', function () {
    $user = User::factory()->create([
        'city' => 'Douala',
        'country_code' => 'CM',
    ]);
    Passport::actingAs($user);

    $idDoc = UploadedFile::fake()->create('cni.pdf', 500, 'application/pdf');
    $propertyDoc = UploadedFile::fake()->create('titre.pdf', 500, 'application/pdf');

    $submitResponse = postJson('/api/landlord/kyc/submit', [
        'full_name' => 'Jean Double',
        'id_type' => 'cni',
        'id_number' => '123456789',
        'property_right' => 'owner',
        'deed_type' => 'land_title',
        'identity_document' => $idDoc,
        'property_document' => $propertyDoc,
        'requested_badge' => 'double',
    ]);

    $submitResponse->assertStatus(201);
    expect($submitResponse->json('data.requested_badge'))->toBe('double');

    // Vérifier l'affichage dans la file d'attente du back-office KYC
    $adminRole = Role::where('slug', 'admin')->first();
    $admin = User::factory()->create([
        'role_id' => $adminRole->id,
    ]);
    Passport::actingAs($admin);

    $queueResponse = getJson('/api/admin/kyc/queue');
    $queueResponse->assertStatus(200);

    $item = collect($queueResponse->json('data'))->firstWhere('account.id', (string) $user->id);
    expect($item)->not->toBeNull()
        ->and($item['badge'])->toBe('double')
        ->and($item['requested_badge'])->toBe('double');
});

it('POST /subscriptions/quote calculates prorata aligned with existing badge renewal date', function () {
    $user = User::factory()->create(['is_verified' => true]);
    Passport::actingAs($user);

    $agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $agentPrice = $agentProduct->activePrices()->where('cadence', 'monthly')->first();

    // L'utilisateur a déjà un abonnement actif pour le badge Agent qui expire dans 15 jours
    $now = Carbon::parse('2026-11-01 12:00:00');
    Carbon::setTestNow($now);

    $existingPeriodEnd = $now->copy()->addDays(15);
    Subscription::create([
        'user_id' => $user->id,
        'product_id' => $agentProduct->id,
        'price_id' => $agentPrice->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'state' => 'active',
        'amount' => $agentPrice->price_amount,
        'currency' => 'XAF',
        'current_period_start' => $now->copy()->subDays(15),
        'current_period_end' => $existingPeriodEnd,
        'auto_renew' => true,
    ]);

    // Devis pour ajouter le badge bailleur aligné avec le badge agent
    $response = postJson('/api/subscriptions/quote', [
        'badge' => 'landlord',
        'cadence' => 'monthly',
        'align_with' => 'agent',
    ]);

    $response->assertStatus(200);
    $data = $response->json('data');

    $landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $landlordPrice = $landlordProduct->activePrices()->where('cadence', 'monthly')->first();
    $fullLandlordAmount = (float) $landlordPrice->price_amount;

    // Prorata pour 15 jours sur 30 jours = 50%
    $expectedProrata = round($fullLandlordAmount * (15 / 30), 2);

    expect((float) $data['amount'])->toBe($expectedProrata)
        ->and($data['currency'])->toBe('XAF')
        ->and($data['period_end'])->toBe($existingPeriodEnd->toISOString())
        ->and($data['align_with'])->toBe('agent')
        ->and($data['prorated'])->toBeTrue();

    Carbon::setTestNow();
});

it('POST /payments/initiate accepts align_with, applies prorata and sets matching period_end', function () {
    $user = User::factory()->create(['is_verified' => true, 'badge' => 'agent']);
    Passport::actingAs($user);

    $agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $agentPrice = $agentProduct->activePrices()->where('cadence', 'monthly')->first();

    $now = Carbon::parse('2026-11-01 12:00:00');
    Carbon::setTestNow($now);

    $existingPeriodEnd = $now->copy()->addDays(15);
    Subscription::create([
        'user_id' => $user->id,
        'product_id' => $agentProduct->id,
        'price_id' => $agentPrice->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'state' => 'active',
        'amount' => $agentPrice->price_amount,
        'currency' => 'XAF',
        'current_period_start' => $now->copy()->subDays(15),
        'current_period_end' => $existingPeriodEnd,
        'auto_renew' => true,
    ]);

    $landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $landlordPrice = $landlordProduct->activePrices()->where('cadence', 'monthly')->first();

    // Initiates payment with align_with
    $response = postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $landlordPrice->id,
        'phone' => '+237699001122',
        'operator' => 'orange',
        'channel' => 'mobile_money',
        'idempotency_key' => 'align-test-12345',
        'align_with' => 'agent',
    ]);

    $response->assertStatus(201);
    $transaction = $response->json('data');

    // Confirmer le paiement et vérifier l'activation de la 2e souscription
    $txModel = PaymentTransaction::where('reference', $transaction['reference'])->first();
    expect($txModel)->not->toBeNull();

    $paymentEngine = app(PaymentEngineService::class);
    $paymentEngine->confirmAndFulfill($txModel, 'EXT-TEST-123', PaymentEventSource::Gateway);

    // Vérifier l'abonnement bailleur créé
    $landlordSub = Subscription::where('user_id', $user->id)
        ->where('badge_type', 'landlord')
        ->first();

    expect($landlordSub)->not->toBeNull()
        ->and($landlordSub->state)->toBe('active')
        ->and($landlordSub->current_period_end->toISOString())->toBe($existingPeriodEnd->toISOString());

    // Vérifier que le badge utilisateur est maintenant double (anneau rouge, badges: Bailleur, Agent)
    $user->refresh();
    expect($user->badge)->toBe('double');

    Carbon::setTestNow();
});
