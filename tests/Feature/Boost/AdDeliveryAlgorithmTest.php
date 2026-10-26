<?php

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Models\Boost\Campaign;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingSave;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Profile\Follow;
use App\Models\Profile\FollowRequest;
use App\Models\Profile\Interest;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Square\PostView;
use App\Models\User;
use App\Services\Boost\AdDeliveryService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);
    Cache::flush();

    $currency = Currency::firstOrCreate(
        ['code' => 'XAF'],
        ['name' => 'Franc CFA', 'symbol' => 'FCFA']
    );

    $this->country = Country::firstOrCreate(
        ['iso2' => 'CM'],
        [
            'code' => 'CM',
            'name' => 'Cameroun',
            'iso3' => 'CMR',
            'currency_id' => $currency->id,
            'is_active' => true,
        ]
    );

    // Coordonnées approximatives : Douala (4.0511, 9.7679) et Yaoundé (3.8480, 11.5021) ~ 200 km
    $this->douala = City::firstOrCreate(
        ['slug' => 'douala'],
        [
            'country_id' => $this->country->id,
            'name' => 'Douala',
            'lat' => 4.0511,
            'lng' => 9.7679,
            'is_active' => true,
        ]
    );

    $this->yaounde = City::firstOrCreate(
        ['slug' => 'yaounde'],
        [
            'country_id' => $this->country->id,
            'name' => 'Yaounde',
            'lat' => 3.8480,
            'lng' => 11.5021,
            'is_active' => true,
        ]
    );

    $this->sponsor = User::factory()->create([
        'name' => 'Sponsor Immobilier',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);

    $this->service = app(AdDeliveryService::class);
});

// =========================================================================
// 1. FILTRE : QUI PEUT VOIR CETTE CAMPAGNE
// =========================================================================

test('Filtre : exclusion du commanditaire lui-même', function () {
    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
    ]);

    $eligible = $this->service->getEligibleCampaigns(Post::class, $this->sponsor);
    expect($eligible)->toBeEmpty();
});

test('Filtre : exclusion des comptes bloqués ou ayant bloqué le commanditaire', function () {
    $blockedUser = User::factory()->create(['city' => 'Douala']);
    $blockingUser = User::factory()->create(['city' => 'Douala']);

    // Le sponsor a bloqué $blockedUser
    $this->sponsor->blockedUsers()->attach($blockedUser->id);

    // $blockingUser a bloqué le sponsor
    $blockingUser->blockedUsers()->attach($this->sponsor->id);

    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'],
    ]);

    expect($this->service->getEligibleCampaigns(Post::class, $blockedUser))->toBeEmpty();
    expect($this->service->getEligibleCampaigns(Post::class, $blockingUser))->toBeEmpty();
});

test('Filtre : exclusion si l\'utilisateur a mis le contenu en "pas intéressé"', function () {
    $viewer = User::factory()->create(['city' => 'Douala']);
    $reel = Reel::factory()->create(['user_id' => $this->sponsor->id]);

    $reel->notInterestedByUsers()->attach($viewer->id);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Reel::class,
        'boostable_id' => (string) $reel->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
    ]);

    $eligible = $this->service->getEligibleCampaigns(Reel::class, $viewer);
    expect($eligible)->toBeEmpty();
});

test('Filtre : distance géographique avec radius_km', function () {
    $viewerYaounde = User::factory()->create(['city' => 'Yaounde']);
    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);

    // Campagne ciblée à Douala avec un rayon de 50 km (Yaoundé est à ~200 km donc exclue)
    $campaign50km = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => [
            'city' => 'Douala',
            'radius_km' => 50,
        ],
    ]);

    expect($this->service->getEligibleCampaigns(Post::class, $viewerYaounde))->toBeEmpty();

    // Si on élargit le rayon à 300 km, Yaoundé devient éligible
    $campaign50km->update([
        'targeting' => [
            'city' => 'Douala',
            'radius_km' => 300,
        ],
    ]);

    expect($this->service->getEligibleCampaigns(Post::class, $viewerYaounde)->pluck('id'))
        ->toContain($campaign50km->id);
});

test('Filtre : ciblage par quartier strict', function () {
    $bonanjoUser = User::factory()->create(['city' => 'Douala', 'neighbourhood' => 'Bonanjo']);
    $akwaUser = User::factory()->create(['city' => 'Douala', 'neighbourhood' => 'Akwa']);

    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => [
            'city' => 'Douala',
            'neighbourhoods' => ['Akwa'],
        ],
    ]);

    expect($this->service->getEligibleCampaigns(Post::class, $bonanjoUser))->toBeEmpty();
    expect($this->service->getEligibleCampaigns(Post::class, $akwaUser)->pluck('id'))->toContain($campaign->id);
});

test('Filtre : ciblage par tranche d\'âge calculée depuis birth_date', function () {
    $youngUser = User::factory()->create([
        'city' => 'Douala',
        'birth_date' => now()->subYears(18)->format('Y-m-d'), // 18 ans
    ]);
    $targetUser = User::factory()->create([
        'city' => 'Douala',
        'birth_date' => now()->subYears(28)->format('Y-m-d'), // 28 ans
    ]);
    $seniorUser = User::factory()->create([
        'city' => 'Douala',
        'birth_date' => now()->subYears(50)->format('Y-m-d'), // 50 ans
    ]);

    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => [
            'city' => 'Douala',
            'age_min' => 25,
            'age_max' => 35,
        ],
    ]);

    expect($this->service->getEligibleCampaigns(Post::class, $youngUser))->toBeEmpty();
    expect($this->service->getEligibleCampaigns(Post::class, $seniorUser))->toBeEmpty();
    expect($this->service->getEligibleCampaigns(Post::class, $targetUser)->pluck('id'))->toContain($campaign->id);
});

test('Filtre : au moins un centre d\'intérêt en commun', function () {
    $interestFashion = Interest::create(['id' => 'fashion', 'name' => 'Mode', 'category' => 'lifestyle', 'is_active' => true]);
    $interestTech = Interest::create(['id' => 'tech', 'name' => 'Technologie', 'category' => 'tech', 'is_active' => true]);
    $interestRealEstate = Interest::create(['id' => 'realestate', 'name' => 'Immobilier', 'category' => 'immo', 'is_active' => true]);

    $userFashion = User::factory()->create(['city' => 'Douala']);
    $userFashion->interests()->attach(['fashion']);

    $userTech = User::factory()->create(['city' => 'Douala']);
    $userTech->interests()->attach(['tech']);

    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => [
            'city' => 'Douala',
            'interests' => ['realestate', 'fashion'],
        ],
    ]);

    expect($this->service->getEligibleCampaigns(Post::class, $userTech))->toBeEmpty();
    expect($this->service->getEligibleCampaigns(Post::class, $userFashion)->pluck('id'))->toContain($campaign->id);
});

test('Filtre : plafond de répétition de 3 affichages par personne par campagne par jour', function () {
    $viewer = User::factory()->create(['city' => 'Douala']);
    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'],
    ]);

    // 0, 1, 2 affichages => éligible
    expect($this->service->getEligibleCampaigns(Post::class, $viewer)->pluck('id'))->toContain($campaign->id);
    $this->service->recordImpressionForCapping($campaign, $viewer); // 1
    $this->service->recordImpressionForCapping($campaign, $viewer); // 2
    expect($this->service->getEligibleCampaigns(Post::class, $viewer)->pluck('id'))->toContain($campaign->id);

    // 3ème affichage
    $this->service->recordImpressionForCapping($campaign, $viewer); // 3
    expect($this->service->getEligibleCampaigns(Post::class, $viewer))->toBeEmpty();
});

// =========================================================================
// 2. SCORING DÉTERMINISTE PAR OBJECTIF
// =========================================================================

test('Scoring : objectif followers exclut les abonnés et les demandes en attente', function () {
    $follower = User::factory()->create(['city' => 'Douala']);
    Follow::create(['follower_id' => $follower->id, 'following_id' => $this->sponsor->id]);

    $pendingFollower = User::factory()->create(['city' => 'Douala']);
    FollowRequest::create([
        'from_id' => $pendingFollower->id,
        'to_id' => $this->sponsor->id,
        'status' => 'pending',
    ]);

    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'objective' => CampaignObjective::ProfileVisits, // Followers / Profile visits
        'status' => CampaignStatus::Active,
    ]);

    expect($this->service->computeObjectiveScore($campaign, $follower))->toBe(0.0);
    expect($this->service->computeObjectiveScore($campaign, $pendingFollower))->toBe(0.0);
});

test('Scoring : objectif reach exclut les personnes ayant déjà vu le contenu', function () {
    $hasSeenUser = User::factory()->create(['city' => 'Douala']);
    $newViewer = User::factory()->create(['city' => 'Douala', 'last_seen_at' => now()->subMinutes(10)]);

    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);
    PostView::create([
        'post_id' => $post->id,
        'user_id' => $hasSeenUser->id,
        'viewed_at' => now()->subHour(),
    ]);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'objective' => CampaignObjective::Reach,
        'status' => CampaignStatus::Active,
    ]);

    expect($this->service->computeObjectiveScore($campaign, $hasSeenUser))->toBe(0.0);
    expect($this->service->computeObjectiveScore($campaign, $newViewer))->toBeGreaterThan(0.0);
});

test('Scoring : objectif messages booste les profils ayant interagi avec l\'immobilier', function () {
    Interest::firstOrCreate(['id' => 'realestate'], ['name' => 'Immobilier', 'category' => 'immo', 'is_active' => true]);

    $immoProspect = User::factory()->create(['city' => 'Douala']);
    $immoProspect->interests()->attach(['realestate']);

    // Prospect a ouvert une conversation récemment
    $conv = Conversation::create(['type' => 'direct', 'created_by_id' => $immoProspect->id]);
    ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $immoProspect->id, 'joined_at' => now()->subDays(5)]);

    $listing = Listing::factory()->create([
        'owner_id' => $this->sponsor->id,
        'city' => 'Douala',
        'kind' => ListingKind::Flat,
        'mode' => ListingMode::Rent,
        'price' => '150000.00',
        'status' => ListingStatus::Published,
    ]);

    // Prospect a sauvegardé un appartement similaire à Douala
    $similarListing = Listing::factory()->create([
        'owner_id' => $this->sponsor->id,
        'city' => 'Douala',
        'kind' => ListingKind::Flat,
        'mode' => ListingMode::Rent,
        'price' => '160000.00',
        'status' => ListingStatus::Published,
    ]);
    ListingSave::create(['user_id' => $immoProspect->id, 'listing_id' => $similarListing->id]);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Listing::class,
        'boostable_id' => (string) $listing->id,
        'objective' => CampaignObjective::Messages,
        'status' => CampaignStatus::Active,
    ]);

    $score = $this->service->computeObjectiveScore($campaign, $immoProspect);
    // Attendus : +4 (conversations récentes) + 4 (bien similaire sauvegardé) + 2 (intérêt realestate) = 10
    expect($score)->toBeGreaterThanOrEqual(10.0);
});

// =========================================================================
// 3. CHOIX ET PACING (RYTHME)
// =========================================================================

test('Pacing : une campagne en retard sur sa dépense a un multiplicateur supérieur à 1', function () {
    $campaignLate = Campaign::factory()->create([
        'budget_amount' => '10000.00',
        'spent_amount' => '1000.00', // Dépensé seulement 1000 sur 5000 attendus
        'duration_days' => 10,
        'start_at' => now()->subDays(5), // 5 jours écoulés => attendu 5000
    ]);

    $multiplier = $this->service->computePacingMultiplier($campaignLate);
    expect($multiplier)->toBeGreaterThan(1.0);
});

test('Pacing : une campagne en avance sur sa dépense a un multiplicateur inférieur à 1', function () {
    $campaignAhead = Campaign::factory()->create([
        'budget_amount' => '10000.00',
        'spent_amount' => '8000.00', // Dépensé 8000 alors que 2000 étaient attendus
        'duration_days' => 10,
        'start_at' => now()->subDays(2), // 2 jours écoulés => attendu 2000
    ]);

    $multiplier = $this->service->computePacingMultiplier($campaignAhead);
    expect($multiplier)->toBeLessThan(1.0);
});

test('Choix : si aucune campagne n\'a un score > 0, aucune publicité n\'est injectée', function () {
    $viewer = User::factory()->create(['city' => 'Douala']);
    $post = Post::factory()->create(['user_id' => $this->sponsor->id]);

    // Visiteur a déjà vu le post
    PostView::create(['post_id' => $post->id, 'user_id' => $viewer->id, 'viewed_at' => now()]);

    // Campagne avec objectif Reach (exclut ceux qui l'ont vu => score = 0)
    Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'objective' => CampaignObjective::Reach,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'],
    ]);

    Passport::actingAs($viewer);
    $response = $this->getJson('/api/square/feed');
    $response->assertOk();

    $sponsored = collect($response->json('data'))->filter(fn ($item) => ($item['is_sponsored'] ?? false) === true);
    expect($sponsored)->toBeEmpty();
});
