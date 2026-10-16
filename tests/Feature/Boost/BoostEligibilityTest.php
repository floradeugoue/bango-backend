<?php

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageStatus;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditWallet;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Square\Post;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $this->landlordPrice = SubscriptionPrice::where('product_id', $this->landlordProduct->id)->first();

    $this->agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $this->agentPrice = SubscriptionPrice::where('product_id', $this->agentProduct->id)->first();

    // Création helper pour utilisateur éligible personnel
    $this->createEligibleUser = function (string $badge = 'landlord', array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'badge' => $badge,
            'is_verified' => true,
            'verification_lapsed' => false,
        ], $attributes));

        $product = $badge === 'agent' ? $this->agentProduct : $this->landlordProduct;
        $price = $badge === 'agent' ? $this->agentPrice : $this->landlordPrice;

        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'price_id' => $price->id,
            'badge_type' => $badge,
            'cadence' => 'monthly',
            'amount' => $price->price_amount,
            'currency' => 'XAF',
            'state' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        CreditWallet::factory()->create([
            'user_id' => $user->id,
            'balance' => '50000.00',
            'currency' => 'XAF',
        ]);

        return $user;
    };
});

// =========================================================================
// AXE A : Éligibilité personnelle (SUB + BADGE + KYC)
// =========================================================================

test('Utilisateur éligible personnel avec badge actif et abonnement peut lancer une campagne', function () {
    $user = ($this->createEligibleUser)('landlord');
    Passport::actingAs($user);

    $post = Post::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status.value', 'active')
        ->assertJsonPath('data.sponsor_type', 'user');
});

test('Utilisateur sans badge professionnel se voit refuser la création de campagne (403)', function () {
    $plainUser = User::factory()->create([
        'badge' => 'none',
        'is_verified' => false,
    ]);
    CreditWallet::factory()->create(['user_id' => $plainUser->id, 'balance' => 50000]);
    Passport::actingAs($plainUser);

    $post = Post::factory()->create(['user_id' => $plainUser->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Utilisateur avec abonnement expiré ne peut pas lancer de campagne (403)', function () {
    $user = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);
    CreditWallet::factory()->create(['user_id' => $user->id, 'balance' => 50000]);

    // Abonnement expiré
    Subscription::create([
        'user_id' => $user->id,
        'product_id' => $this->landlordProduct->id,
        'price_id' => $this->landlordPrice->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->landlordPrice->price_amount,
        'currency' => 'XAF',
        'state' => 'expired',
        'current_period_start' => now()->subMonths(2),
        'current_period_end' => now()->subMonth(),
        'auto_renew' => false,
    ]);

    Passport::actingAs($user);
    $post = Post::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Utilisateur avec abonnement actif mais KYC caduc (verification_lapsed) est refusé (403)', function () {
    $user = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => true, // KYC caduc !
    ]);
    CreditWallet::factory()->create(['user_id' => $user->id, 'balance' => 50000]);

    Subscription::create([
        'user_id' => $user->id,
        'product_id' => $this->landlordProduct->id,
        'price_id' => $this->landlordPrice->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->landlordPrice->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'auto_renew' => true,
    ]);

    Passport::actingAs($user);
    $post = Post::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Utilisateur suspendu ne peut pas créer de campagne (403)', function () {
    $user = ($this->createEligibleUser)('landlord', ['status' => 'suspended']);
    Passport::actingAs($user);

    $post = Post::factory()->create(['user_id' => $user->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Tentative de promotion d\'un contenu tiers par un compte personnel est refusée (403)', function () {
    $user = ($this->createEligibleUser)('landlord');
    $otherUser = ($this->createEligibleUser)('agent');

    Passport::actingAs($user);
    $foreignPost = Post::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $foreignPost->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

// =========================================================================
// AXE B : Sponsoring Page Pro (PG + RBAC + Certification)
// =========================================================================

test('Owner d\'une Page certifiée et active peut lancer une campagne au nom de la Page', function () {
    $owner = User::factory()->create(['name' => 'Directeur Agence']);
    CreditWallet::factory()->create(['user_id' => $owner->id, 'balance' => 50000]);

    $page = Page::factory()->create([
        'owner_id' => $owner->id,
        'name' => 'Agence Immobilière Prestige',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $owner->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $post = Post::factory()->create([
        'user_id' => $owner->id,
        'page_id' => $page->id,
    ]);

    Passport::actingAs($owner);

    $response = $this->postJson('/api/boost/campaigns', [
        'page_id' => $page->id,
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 10000,
        'duration_days' => 5,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status.value', 'active')
        ->assertJsonPath('data.page_id', $page->id)
        ->assertJsonPath('data.sponsor_type', 'page')
        ->assertJsonPath('data.page.name', 'Agence Immobilière Prestige');
});

test('Editor d\'une Page certifiée et active peut lancer une campagne au nom de la Page', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create(['name' => 'Community Manager']);
    CreditWallet::factory()->create(['user_id' => $editor->id, 'balance' => 50000]);

    $page = Page::factory()->create([
        'owner_id' => $owner->id,
        'name' => 'Agence Immobilière Prestige',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $editor->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $post = Post::factory()->create([
        'user_id' => $editor->id,
        'page_id' => $page->id,
    ]);

    Passport::actingAs($editor);

    $response = $this->postJson('/api/boost/campaigns', [
        'page_id' => $page->id,
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 7000,
        'duration_days' => 3,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.page_id', $page->id);
});

test('Moderator d\'une Page ne peut PAS lancer de campagne publicitaire (403)', function () {
    $owner = User::factory()->create();
    $moderator = User::factory()->create(['name' => 'Modérateur']);
    CreditWallet::factory()->create(['user_id' => $moderator->id, 'balance' => 50000]);

    $page = Page::factory()->create([
        'owner_id' => $owner->id,
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $moderator->id,
        'role' => PageRole::Moderator,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $post = Post::factory()->create([
        'user_id' => $owner->id,
        'page_id' => $page->id,
    ]);

    Passport::actingAs($moderator);

    $response = $this->postJson('/api/boost/campaigns', [
        'page_id' => $page->id,
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 5,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Membre révoqué d\'une Page perd immédiatement le droit de sponsoriser (403)', function () {
    $owner = User::factory()->create();
    $exEditor = User::factory()->create(['name' => 'Ancien Éditeur']);
    CreditWallet::factory()->create(['user_id' => $exEditor->id, 'balance' => 50000]);

    $page = Page::factory()->create([
        'owner_id' => $owner->id,
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $exEditor->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Revoked, // Membre révoqué !
        'revoked_at' => now(),
    ]);

    $post = Post::factory()->create([
        'user_id' => $owner->id,
        'page_id' => $page->id,
    ]);

    Passport::actingAs($exEditor);

    $response = $this->postJson('/api/boost/campaigns', [
        'page_id' => $page->id,
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 5,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Page Pro non certifiée ne peut pas lancer de campagne sponsorisée (403)', function () {
    $owner = User::factory()->create();
    CreditWallet::factory()->create(['user_id' => $owner->id, 'balance' => 50000]);

    $page = Page::factory()->create([
        'owner_id' => $owner->id,
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::None, // Non certifiée !
    ]);

    $post = Post::factory()->create([
        'user_id' => $owner->id,
        'page_id' => $page->id,
    ]);

    Passport::actingAs($owner);

    $response = $this->postJson('/api/boost/campaigns', [
        'page_id' => $page->id,
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 5,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Page Pro suspendue ne peut pas faire l\'objet de sponsoring (403)', function () {
    $owner = User::factory()->create();
    CreditWallet::factory()->create(['user_id' => $owner->id, 'balance' => 50000]);

    $page = Page::factory()->create([
        'owner_id' => $owner->id,
        'status' => PageStatus::Suspended, // Suspendue !
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    $post = Post::factory()->create([
        'user_id' => $owner->id,
        'page_id' => $page->id,
    ]);

    Passport::actingAs($owner);

    $response = $this->postJson('/api/boost/campaigns', [
        'page_id' => $page->id,
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 5,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Tentative de sponsoriser au nom d\'une Page dont l\'utilisateur ne fait pas partie est refusée (403)', function () {
    $pageOwner = User::factory()->create();
    $stranger = ($this->createEligibleUser)('landlord');

    $page = Page::factory()->create([
        'owner_id' => $pageOwner->id,
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    $post = Post::factory()->create([
        'user_id' => $pageOwner->id,
        'page_id' => $page->id,
    ]);

    Passport::actingAs($stranger);

    $response = $this->postJson('/api/boost/campaigns', [
        'page_id' => $page->id,
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 5,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('Tentative d\'accéder à la campagne d\'un autre commanditaire est bloquée (IDOR / 403)', function () {
    $userA = ($this->createEligibleUser)('landlord');
    $userB = ($this->createEligibleUser)('agent');

    $campaignA = Campaign::factory()->create(['user_id' => $userA->id]);

    Passport::actingAs($userB);

    $response = $this->getJson("/api/boost/campaigns/{$campaignA->id}");
    $response->assertStatus(403);
});
