<?php

use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Enums\Listing\ListingStatus;
use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageStatus;
use App\Exceptions\Boost\InsufficientCreditException;
use App\Exceptions\Boost\InvalidCampaignTransitionException;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditWallet;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Square\Post;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Notifications\Boost\CampaignActivatedNotification;
use App\Notifications\Boost\CampaignSuspendedNotification;
use App\Services\Boost\AdDeliveryService;
use App\Services\Boost\CampaignService;
use App\Services\Boost\CreditWalletService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->campaignService = app(CampaignService::class);
    $this->walletService = app(CreditWalletService::class);
    $this->adDeliveryService = app(AdDeliveryService::class);

    $this->user = User::factory()->create([
        'name' => 'Bailleur Test',
        'city' => 'Douala',
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);

    $landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $landlordPrice = SubscriptionPrice::where('product_id', $landlordProduct->id)->first();
    Subscription::create([
        'user_id' => $this->user->id,
        'product_id' => $landlordProduct->id,
        'price_id' => $landlordPrice->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $landlordPrice->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'auto_renew' => true,
    ]);

    $this->wallet = CreditWallet::factory()->create([
        'user_id' => $this->user->id,
        'balance' => '50000.00',
        'currency' => 'XAF',
    ]);
});

// =========================================================================
// AXE C & E : Cycle de vie et transitions d'état
// =========================================================================

test('Matrice de cycle de vie : transitions autorisées draft -> pending -> active -> paused -> active -> completed / cancelled', function () {
    $campaign = Campaign::factory()->draft()->create([
        'user_id' => $this->user->id,
    ]);

    expect($campaign->canTransitionTo(CampaignStatus::Pending))->toBeTrue()
        ->and($campaign->canTransitionTo(CampaignStatus::Cancelled))->toBeTrue()
        ->and($campaign->canTransitionTo(CampaignStatus::Completed))->toBeFalse();

    $campaign->status = CampaignStatus::Active;
    expect($campaign->canTransitionTo(CampaignStatus::Paused))->toBeTrue()
        ->and($campaign->canTransitionTo(CampaignStatus::Suspended))->toBeTrue()
        ->and($campaign->canTransitionTo(CampaignStatus::Completed))->toBeTrue()
        ->and($campaign->canTransitionTo(CampaignStatus::Cancelled))->toBeTrue();

    $campaign->status = CampaignStatus::Suspended;
    expect($campaign->canTransitionTo(CampaignStatus::Active))->toBeTrue()
        ->and($campaign->canTransitionTo(CampaignStatus::Cancelled))->toBeTrue()
        ->and($campaign->canTransitionTo(CampaignStatus::Completed))->toBeFalse();

    $campaign->status = CampaignStatus::Completed;
    expect($campaign->canTransitionTo(CampaignStatus::Active))->toBeFalse()
        ->and($campaign->canTransitionTo(CampaignStatus::Paused))->toBeFalse();
});

test('Transition de statut interdite lève une exception InvalidCampaignTransitionException', function () {
    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'status' => CampaignStatus::Completed,
    ]);

    expect(fn () => $this->campaignService->pauseCampaign($campaign, $this->user))
        ->toThrow(InvalidCampaignTransitionException::class);
});

test('Suspension d\'une campagne active met à jour le statut, le motif et envoie une notification', function () {
    Notification::fake();

    $post = Post::factory()->create(['user_id' => $this->user->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
    ]);

    $suspended = $this->campaignService->suspendCampaign($campaign, 'moderation_violation');

    expect($suspended->status)->toBe(CampaignStatus::Suspended)
        ->and($suspended->stopped_reason)->toBe('moderation_violation');

    Notification::assertSentTo($this->user, CampaignSuspendedNotification::class);
});

// =========================================================================
// AXE E : Règles EDGE — Biens immobiliers (IM / BL) et Modération
// =========================================================================

test('Règle EDGE IM : Une annonce immobilière marquée taken n\'est plus diffusée dans le moteur publicitaire', function () {
    $listing = Listing::factory()->create([
        'owner_id' => $this->user->id,
        'status' => ListingStatus::Published,
    ]);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'boostable_type' => Listing::class,
        'boostable_id' => (string) $listing->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
    ]);

    // 1. Initialement éligible
    $eligible = $this->adDeliveryService->getEligibleCampaigns(Listing::class, $this->user);
    expect($eligible->pluck('id'))->toContain($campaign->id);

    // 2. Le bien devient loué / indisponible (taken)
    $listing->update(['status' => ListingStatus::Taken]);

    // 3. Le moteur publicitaire exclut immédiatement la campagne
    $eligibleAfter = $this->adDeliveryService->getEligibleCampaigns(Listing::class, $this->user);
    expect($eligibleAfter->pluck('id'))->not->toContain($campaign->id);
});

test('Règle EDGE SUP : Une Page Pro suspendue entraîne l\'exclusion immédiate de ses campagnes de la diffusion', function () {
    $page = Page::factory()->create([
        'owner_id' => $this->user->id,
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    $post = Post::factory()->create([
        'user_id' => $this->user->id,
        'page_id' => $page->id,
    ]);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'page_id' => $page->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
    ]);

    $eligible = $this->adDeliveryService->getEligibleCampaigns(Post::class, $this->user);
    expect($eligible->pluck('id'))->toContain($campaign->id);

    // Suspension de la Page par la modération
    $page->update(['status' => PageStatus::Suspended]);

    $eligibleAfter = $this->adDeliveryService->getEligibleCampaigns(Post::class, $this->user);
    expect($eligibleAfter->pluck('id'))->not->toContain($campaign->id);
});

test('Règle EDGE SUP : Un post archivé ou dont l\'auteur est suspendu n\'est plus diffusé en campagne active', function () {
    $post = Post::factory()->create(['user_id' => $this->user->id]);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
    ]);

    // Archivage du post
    $post->update(['is_archived' => true]);

    $eligible = $this->adDeliveryService->getEligibleCampaigns(Post::class, $this->user);
    expect($eligible->pluck('id'))->not->toContain($campaign->id);
});

// =========================================================================
// AXE C : Crédit BANGO et Cohérence Financière
// =========================================================================

test('Crédit BANGO : Débit atomique avec clé d\'idempotence empêche tout double débit lors d\'un rejeu réseau', function () {
    $initialBalance = $this->walletService->getBalance($this->user);
    expect($initialBalance)->toBe(50000.0);

    $idempotencyKey = 'unique_retry_test_key_8899';

    // Premier débit
    $tx1 = $this->walletService->debit(
        account: $this->user,
        amount: 5000,
        source: CreditTransactionType::CampaignDebit,
        reference: 'camp_8899',
        description: 'Paiement campagne boost',
        idempotencyKey: $idempotencyKey
    );

    expect($this->walletService->getBalance($this->user))->toBe(45000.0);

    // Rejeu identique de la requête avec la même clé d'idempotence
    $tx2 = $this->walletService->debit(
        account: $this->user,
        amount: 5000,
        source: CreditTransactionType::CampaignDebit,
        reference: 'camp_8899',
        description: 'Paiement campagne boost rejoué',
        idempotencyKey: $idempotencyKey
    );

    // Le solde ne doit PAS avoir diminué une seconde fois
    expect($this->walletService->getBalance($this->user))->toBe(45000.0)
        ->and($tx2->id)->toBe($tx1->id);
});

test('Crédit BANGO : Refus strict et exception InsufficientCreditException si solde inférieur au montant requis', function () {
    $poorUser = User::factory()->create();
    CreditWallet::factory()->create(['user_id' => $poorUser->id, 'balance' => 500]);

    expect(fn () => $this->walletService->debit(
        account: $poorUser,
        amount: 2000,
        source: CreditTransactionType::CampaignDebit,
        reference: 'overdraft_attempt'
    ))->toThrow(InsufficientCreditException::class);

    // Solde reste intact
    expect($this->walletService->getBalance($poorUser))->toBe(500.0);
});

test('Notification envoyée au créateur lors de la création d\'une campagne active avec Crédit BANGO', function () {
    Notification::fake();

    $post = Post::factory()->create(['user_id' => $this->user->id]);

    $campaign = $this->campaignService->createCampaign($this->user, [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 5,
        'payment_method' => 'credit',
    ]);

    expect($campaign->status)->toBe(CampaignStatus::Active);

    Notification::assertSentTo($this->user, CampaignActivatedNotification::class);
});
