<?php

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageStatus;
use App\Enums\Reel\ReelStatus;
use App\Enums\Square\PostAudience;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditWallet;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $this->landlordPrice = SubscriptionPrice::where('product_id', $this->landlordProduct->id)->first();

    $this->agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $this->agentPrice = SubscriptionPrice::where('product_id', $this->agentProduct->id)->first();

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
        ]);

        return $user;
    };
});

it('retourne les publications et reels eligibles avec calcul de percentile de surperformance pour un utilisateur certifie', function () {
    $user = ($this->createEligibleUser)('landlord');

    // Créer 4 contenus avec différents niveaux d'engagement
    $postLow = Post::create([
        'user_id' => $user->id,
        'body' => 'Post peu performant',
        'audience' => PostAudience::Public,
        'likes_count' => 5,
        'comments_count' => 1,
        'shares_count' => 0,
    ]);

    $postMid = Post::create([
        'user_id' => $user->id,
        'body' => 'Post moyennement performant',
        'audience' => PostAudience::Public,
        'likes_count' => 50,
        'comments_count' => 10,
        'shares_count' => 2,
    ]);

    $reelTop = Reel::create([
        'user_id' => $user->id,
        'caption' => 'Reel viral star',
        'video_url' => 'videos/viral.mp4',
        'status' => ReelStatus::Ready,
        'views_count' => 1000,
        'likes_count' => 200,
        'comments_count' => 50,
        'shares_count' => 20,
    ]);

    $postOther = Post::create([
        'user_id' => $user->id,
        'body' => 'Post moyen 2',
        'audience' => PostAudience::Public,
        'likes_count' => 30,
        'comments_count' => 5,
        'shares_count' => 1,
    ]);

    Passport::actingAs($user);

    $response = getJson('/api/boost/boostable');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'content_id',
                    'type',
                    'title',
                    'caption',
                    'thumbnail_url',
                    'metrics' => [
                        'views',
                        'likes',
                        'comments',
                        'shares',
                        'engagement_score',
                    ],
                    'outperformance' => [
                        'percentile',
                        'label',
                        'has_sufficient_data',
                    ],
                ],
            ],
            'meta' => ['total', 'current_page'],
        ]);

    $data = $response->json('data');
    expect(count($data))->toBe(4);

    // Le contenu le plus performant est le premier
    expect($data[0]['id'])->toBe((string) $reelTop->id);
    expect($data[0]['type'])->toBe('reel');
    expect($data[0]['outperformance']['has_sufficient_data'])->toBeTrue();
    // Le meilleur contenu surpasse les 3 autres (100% ou 99%)
    expect($data[0]['outperformance']['percentile'])->toBeGreaterThanOrEqual(80);
    expect($data[0]['outperformance']['label'])->toContain('Performe mieux que');

    // Le contenu le moins performant
    $lowest = collect($data)->firstWhere('id', (string) $postLow->id);
    expect($lowest['outperformance']['percentile'])->toBeLessThanOrEqual(25);
});

it('retourne un etat donnees insuffisantes si l utilisateur possede moins de 3 contenus', function () {
    $user = ($this->createEligibleUser)('landlord');

    Post::create([
        'user_id' => $user->id,
        'body' => 'Seul post du compte',
        'audience' => PostAudience::Public,
        'likes_count' => 10,
    ]);

    Passport::actingAs($user);

    $response = getJson('/api/boost/boostable');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect(count($data))->toBe(1);
    expect($data[0]['outperformance']['has_sufficient_data'])->toBeFalse();
    expect($data[0]['outperformance']['percentile'])->toBeNull();
    expect($data[0]['outperformance']['label'])->toContain('Données insuffisantes');
});

it('refuse l acces 403 a un utilisateur personnel non certifie sans badge pro', function () {
    $unverifiedUser = User::factory()->create([
        'badge' => 'none',
        'is_verified' => false,
        'status' => 'active',
    ]);

    Passport::actingAs($unverifiedUser);

    $response = getJson('/api/boost/boostable');
    $response->assertStatus(403);
});

it('refuse l acces 403 si la verification KYC de l utilisateur est caduque (verification_lapsed)', function () {
    $lapsedUser = ($this->createEligibleUser)('agent', ['verification_lapsed' => true]);

    Passport::actingAs($lapsedUser);

    $response = getJson('/api/boost/boostable');
    $response->assertStatus(403);
});

it('permet a une Page Professionnelle certifiee de lister ses contenus boostables', function () {
    $owner = User::factory()->create(['status' => 'active']);

    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Agence Immobilière Prestige',
        'handle' => 'agence_prestige',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
        'certified_at' => now(),
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $owner->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
    ]);

    // Publications rattachées à la Page
    $pagePost = Post::create([
        'user_id' => $owner->id,
        'page_id' => $page->id,
        'body' => 'Appartement standing disponible',
        'audience' => PostAudience::Public,
        'likes_count' => 15,
    ]);

    Passport::actingAs($owner);

    $response = getJson("/api/boost/boostable?page_id={$page->id}");
    $response->assertStatus(200);

    $data = $response->json('data');
    expect(count($data))->toBe(1);
    expect($data[0]['id'])->toBe((string) $pagePost->id);
});

it('refuse l acces si la Page Professionnelle n est pas certifiee', function () {
    $owner = User::factory()->create(['status' => 'active']);

    $uncertifiedPage = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Boutique Non Certifiée',
        'handle' => 'boutique_non_certifiee',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Pending,
    ]);

    PageMember::create([
        'page_id' => $uncertifiedPage->id,
        'user_id' => $owner->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
    ]);

    Passport::actingAs($owner);

    $response = getJson("/api/boost/boostable?page_id={$uncertifiedPage->id}");
    $response->assertStatus(403);
});

it('ne cree aucune campagne et ne debite aucun credit lors de la consultation des contenus boostables', function () {
    $user = ($this->createEligibleUser)('landlord');

    Post::create([
        'user_id' => $user->id,
        'body' => 'Contenu de test invariance financière',
        'audience' => PostAudience::Public,
        'likes_count' => 25,
    ]);

    $wallet = CreditWallet::where('user_id', $user->id)->first();
    $initialBalance = $wallet->balance;

    Passport::actingAs($user);

    $initialCampaignCount = Campaign::count();

    $response = getJson('/api/boost/boostable');
    $response->assertStatus(200);

    // Aucune campagne créée
    expect(Campaign::count())->toBe($initialCampaignCount);

    // Aucun débit
    $wallet->refresh();
    expect((string) $wallet->balance)->toBe((string) $initialBalance);
});
