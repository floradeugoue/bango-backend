<?php

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Models\Boost\Campaign;
use App\Models\Boost\CampaignImpression;
use App\Models\Boost\CampaignResult;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Profile\Interest;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Boost\AdDeliveryService;
use App\Services\Boost\CampaignAttributionService;
use App\Services\Boost\CampaignService;
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

    $this->sponsor = User::factory()->create([
        'name' => 'Commanditaire Pro',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'badge' => 'landlord',
        'is_verified' => true,
    ]);

    $this->viewer = User::factory()->create([
        'name' => 'Spectateur Cible',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'birth_date' => now()->subYears(28)->format('Y-m-d'),
    ]);

    $this->post = Post::factory()->create(['user_id' => $this->sponsor->id]);

    $this->campaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $this->post->id,
        'status' => CampaignStatus::Active,
        'objective' => CampaignObjective::ProfileVisits,
        'budget_amount' => '10000.00',
        'spent_amount' => '1000.00',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'],
    ]);

    $this->campaignService = app(CampaignService::class);
    $this->attributionService = app(CampaignAttributionService::class);
    $this->deliveryService = app(AdDeliveryService::class);
});

// =========================================================================
// 1. IMPRESSIONS & CLICS UNITAIRES + REACH DISTINCT
// =========================================================================

test('POST /impression enregistre qui a vu quoi et compte les personnes uniques pour reach_count', function () {
    Passport::actingAs($this->viewer);

    // 1ère impression pour ce spectateur
    $res1 = $this->postJson("/api/boost/campaigns/{$this->campaign->id}/impression");
    $res1->assertOk()
        ->assertJsonPath('impressions_count', 1)
        ->assertJsonPath('reach_count', 1);

    $this->assertDatabaseHas('campaign_impressions', [
        'campaign_id' => $this->campaign->id,
        'user_id' => $this->viewer->id,
    ]);

    // 2ème impression pour le MÊME spectateur
    $res2 = $this->postJson("/api/boost/campaigns/{$this->campaign->id}/impression");
    $res2->assertOk()
        ->assertJsonPath('impressions_count', 2)
        ->assertJsonPath('reach_count', 1); // 1 seule personne distincte !

    expect($this->campaign->fresh()->reach_count)->toBe(1)
        ->and($this->campaign->fresh()->impressions_count)->toBe(2);

    // Nouveau spectateur distinct
    $secondViewer = User::factory()->create(['city' => 'Douala']);
    Passport::actingAs($secondViewer);

    $res3 = $this->postJson("/api/boost/campaigns/{$this->campaign->id}/impression");
    $res3->assertOk()
        ->assertJsonPath('impressions_count', 3)
        ->assertJsonPath('reach_count', 2); // 2 personnes distinctes !

    // Vérification de la dépense consommée : 2 personnes distinctes = 2 * (2180 / 1000) = 4.36 XAF
    // Initialement 1000.00 -> 1004.36
    expect((float) $this->campaign->fresh()->spent_amount)->toBe(1004.36);
});

test('POST /impression passe la campagne en completed lorsque le budget est épuisé', function () {
    $viewer1 = User::factory()->create();
    $viewer2 = User::factory()->create();

    // Campagne avec un budget très faible : 3.00 XAF (CPM = 2180 XAF -> 2.18 XAF par nouvelle personne)
    $lowBudgetCampaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $this->post->id,
        'status' => CampaignStatus::Active,
        'budget_amount' => '3.00',
        'spent_amount' => '0.00',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(2),
    ]);

    // 1ère impression -> dépense 2.18 XAF (spent < budget -> reste Active)
    Passport::actingAs($viewer1);
    $this->postJson("/api/boost/campaigns/{$lowBudgetCampaign->id}/impression")->assertOk();
    expect($lowBudgetCampaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and((float) $lowBudgetCampaign->fresh()->spent_amount)->toBe(2.18);

    // 2ème impression par une nouvelle personne -> dépense 2.18 XAF supplémentaire -> total 4.36 >= 3.00
    Passport::actingAs($viewer2);
    $this->postJson("/api/boost/campaigns/{$lowBudgetCampaign->id}/impression")->assertOk();
    expect($lowBudgetCampaign->fresh()->status)->toBe(CampaignStatus::Completed)
        ->and($lowBudgetCampaign->fresh()->canBeServed())->toBeFalse();

    // Une tentative suivante d'impression doit être rejetée en 422
    $viewer3 = User::factory()->create();
    Passport::actingAs($viewer3);
    $this->postJson("/api/boost/campaigns/{$lowBudgetCampaign->id}/impression")->assertStatus(422);
});

test('une campagne passe en completed lorsque sa date de fin end_at est dépassée', function () {
    $expiredCampaign = Campaign::factory()->create([
        'user_id' => $this->sponsor->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $this->post->id,
        'status' => CampaignStatus::Active,
        'budget_amount' => '5000.00',
        'spent_amount' => '100.00',
        'start_at' => now()->subDays(5),
        'end_at' => now()->subMinute(), // Déjà expirée
    ]);

    expect($expiredCampaign->canBeServed())->toBeFalse()
        ->and($expiredCampaign->fresh()->status)->toBe(CampaignStatus::Completed);
});

test('POST /click enregistre le clic unitaire dans campaign_clicks', function () {
    Passport::actingAs($this->viewer);

    $res = $this->postJson("/api/boost/campaigns/{$this->campaign->id}/click");
    $res->assertOk()
        ->assertJsonPath('clicks_count', 1);

    $this->assertDatabaseHas('campaign_clicks', [
        'campaign_id' => $this->campaign->id,
        'user_id' => $this->viewer->id,
    ]);
});

// =========================================================================
// 2. ATTRIBUTION AUTOMATIQUE DANS LES 24 H
// =========================================================================

test('Attribution : un abonnement au commanditaire dans les 24h suivant une impression est attribué', function () {
    Passport::actingAs($this->viewer);

    // 1. Spectateur voit la campagne
    $this->postJson("/api/boost/campaigns/{$this->campaign->id}/impression")->assertOk();
    $impression = CampaignImpression::where('campaign_id', $this->campaign->id)->where('user_id', $this->viewer->id)->first();

    // 2. Spectateur s'abonne au commanditaire
    $followRes = $this->postJson("/api/profile/{$this->sponsor->id}/follow");
    $followRes->assertOk();

    // 3. Vérification de l'attribution
    $this->assertDatabaseHas('campaign_results', [
        'campaign_id' => $this->campaign->id,
        'user_id' => $this->viewer->id,
        'type' => 'follow',
        'impression_id' => $impression->id,
    ]);
});

test('Attribution : une visite de profil au commanditaire dans les 24h suivant une impression est attribuée', function () {
    Passport::actingAs($this->viewer);

    // 1. Impression
    $this->postJson("/api/boost/campaigns/{$this->campaign->id}/impression")->assertOk();
    $impression = CampaignImpression::where('campaign_id', $this->campaign->id)->where('user_id', $this->viewer->id)->first();

    // 2. Visite du profil public du commanditaire
    $this->getJson("/api/profile/public/{$this->sponsor->id}")->assertOk();

    // 3. Vérification de l'attribution
    $this->assertDatabaseHas('campaign_results', [
        'campaign_id' => $this->campaign->id,
        'user_id' => $this->viewer->id,
        'type' => 'profile_visit',
        'impression_id' => $impression->id,
    ]);
});

test('Attribution : un message envoyé au commanditaire dans les 24h suivant une impression est attribué', function () {
    Passport::actingAs($this->viewer);

    // 1. Impression
    $this->postJson("/api/boost/campaigns/{$this->campaign->id}/impression")->assertOk();
    $impression = CampaignImpression::where('campaign_id', $this->campaign->id)->where('user_id', $this->viewer->id)->first();

    // 2. Création d'une conversation et envoi de message
    $thread = Conversation::create([
        'type' => 'direct',
        'created_by_id' => $this->viewer->id,
    ]);
    ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->viewer->id]);
    ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->sponsor->id]);

    $msgRes = $this->postJson("/api/messages/threads/{$thread->id}/messages", [
        'body' => 'Bonjour, je suis intéressé par votre annonce !',
    ]);
    $msgRes->assertStatus(201);

    // 3. Vérification de l'attribution
    $this->assertDatabaseHas('campaign_results', [
        'campaign_id' => $this->campaign->id,
        'user_id' => $this->viewer->id,
        'type' => 'message',
        'impression_id' => $impression->id,
    ]);
});

test('Attribution : pas d\'attribution si l\'impression date de plus de 24h', function () {
    Passport::actingAs($this->viewer);

    // Impression datant de 30 heures
    CampaignImpression::create([
        'campaign_id' => $this->campaign->id,
        'user_id' => $this->viewer->id,
        'shown_at' => now()->subHours(30),
    ]);

    // Spectateur s'abonne
    $this->postJson("/api/profile/{$this->sponsor->id}/follow")->assertOk();

    // Pas de résultat attribué
    $this->assertDatabaseMissing('campaign_results', [
        'campaign_id' => $this->campaign->id,
        'user_id' => $this->viewer->id,
        'type' => 'follow',
    ]);
});

// =========================================================================
// 3. APPRENTISSAGE ADAPTATIF À PARTIR DE ~200 IMPRESSIONS
// =========================================================================

test('Apprentissage : booste le score des tranches (âge, quartier, intérêts) qui sur-convertissent', function () {
    Interest::firstOrCreate(['id' => 'fashion'], ['name' => 'Mode', 'category' => 'lifestyle', 'is_active' => true]);
    Interest::firstOrCreate(['id' => 'tech'], ['name' => 'Tech', 'category' => 'tech', 'is_active' => true]);

    $this->campaign->update(['impressions_count' => 250]);

    // Générer 200 impressions historiques :
    // - 100 pour Akwa (avec 20 conversions = 20%)
    // - 100 pour Bonanjo (avec 2 conversions = 2%)
    for ($i = 0; $i < 100; $i++) {
        $uAkwa = User::factory()->create([
            'city' => 'Douala',
            'neighbourhood' => 'Akwa',
            'birth_date' => now()->subYears(28)->format('Y-m-d'), // 25-34
        ]);
        $uAkwa->interests()->attach(['fashion']);

        $imp = CampaignImpression::create([
            'campaign_id' => $this->campaign->id,
            'user_id' => $uAkwa->id,
            'shown_at' => now()->subHours(5),
        ]);

        if ($i < 20) {
            CampaignResult::create([
                'campaign_id' => $this->campaign->id,
                'user_id' => $uAkwa->id,
                'type' => 'follow',
                'impression_id' => $imp->id,
                'occurred_at' => now()->subHours(4),
            ]);
        }
    }

    for ($i = 0; $i < 100; $i++) {
        $uBonanjo = User::factory()->create([
            'city' => 'Douala',
            'neighbourhood' => 'Bonanjo',
            'birth_date' => now()->subYears(50)->format('Y-m-d'), // 45+
        ]);
        $uBonanjo->interests()->attach(['tech']);

        $imp = CampaignImpression::create([
            'campaign_id' => $this->campaign->id,
            'user_id' => $uBonanjo->id,
            'shown_at' => now()->subHours(5),
        ]);

        if ($i < 2) {
            CampaignResult::create([
                'campaign_id' => $this->campaign->id,
                'user_id' => $uBonanjo->id,
                'type' => 'follow',
                'impression_id' => $imp->id,
                'occurred_at' => now()->subHours(4),
            ]);
        }
    }

    // Utilisateur du segment gagnant (Akwa, 28 ans, Fashion)
    $candidateWinning = User::factory()->create([
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'birth_date' => now()->subYears(28)->format('Y-m-d'),
    ]);
    $candidateWinning->interests()->attach(['fashion']);

    // Utilisateur du segment sous-performant (Bonanjo, 50 ans, Tech)
    $candidateUnderperforming = User::factory()->create([
        'city' => 'Douala',
        'neighbourhood' => 'Bonanjo',
        'birth_date' => now()->subYears(50)->format('Y-m-d'),
    ]);
    $candidateUnderperforming->interests()->attach(['tech']);

    $scoreWinning = $this->deliveryService->computeObjectiveScore($this->campaign, $candidateWinning);
    $scoreUnderperforming = $this->deliveryService->computeObjectiveScore($this->campaign, $candidateUnderperforming);

    // Le segment gagnant doit avoir reçu un sur-poids significatif grâce à l'apprentissage
    expect($scoreWinning)->toBeGreaterThan($scoreUnderperforming);

    $boostWinning = $this->deliveryService->computeLearnedSliceBoost($this->campaign, $candidateWinning, ['fashion']);
    expect($boostWinning)->toBeGreaterThan(0.0);
});

// =========================================================================
// 6. BO-07 : AUDIENCE BREAKDOWN & THUMBNAIL_URL DANS CAMPAIGNRESOURCE
// =========================================================================

test('BO-07 : CampaignResource retourne thumbnail_url et audience_breakdown calcule sur campaign_impressions', function () {
    Passport::actingAs($this->sponsor);

    // Ajouter un média sur le post de la campagne pour le thumbnail_url
    \App\Models\Square\PostMedia::create([
        'post_id' => $this->post->id,
        'media_url' => 'https://res.cloudinary.com/bango/image/upload/v1234/test_post_thumb.jpg',
        'media_type' => \App\Enums\Square\PostMediaKind::Photo,
        'display_order' => 0,
    ]);

    // Créer des spectateurs avec quartiers et âges distincts
    // 3 personnes des Almadies (28 ans -> tranche 25-34 ans)
    // 1 personne de Plateau (40 ans -> tranche 35-44 ans)
    $spectatorsAlmadies = [];
    for ($i = 0; $i < 3; $i++) {
        $u = User::factory()->create([
            'neighbourhood' => 'Almadies',
            'birth_date' => now()->subYears(28)->format('Y-m-d'),
        ]);
        $spectatorsAlmadies[] = $u;

        CampaignImpression::create([
            'campaign_id' => $this->campaign->id,
            'user_id' => $u->id,
            'shown_at' => now()->subHours(2),
        ]);
    }

    $spectatorPlateau = User::factory()->create([
        'neighbourhood' => 'Plateau',
        'birth_date' => now()->subYears(40)->format('Y-m-d'),
    ]);
    CampaignImpression::create([
        'campaign_id' => $this->campaign->id,
        'user_id' => $spectatorPlateau->id,
        'shown_at' => now()->subHour(),
    ]);

    // Appel GET /api/boost/campaigns/{id}
    $resShow = $this->getJson("/api/boost/campaigns/{$this->campaign->id}");
    $resShow->assertOk();

    // Vérifier la présence et la valeur de thumbnail_url
    $resShow->assertJsonPath('data.thumbnail_url', 'https://res.cloudinary.com/bango/image/upload/v1234/test_post_thumb.jpg');

    // Vérifier audience_breakdown :
    // Total 4 spectateurs uniques :
    // - Almadies : 3 / 4 = 75% (0.75)
    // - Plateau : 1 / 4 = 25% (0.25)
    // Tranches d'âge :
    // - 25-34 ans : 3 / 4 = 0.75
    // - 35-44 ans : 1 / 4 = 0.25
    $resShow->assertJsonPath('data.audience_breakdown.neighbourhoods.0.name', 'Almadies')
        ->assertJsonPath('data.audience_breakdown.neighbourhoods.0.share', 0.75)
        ->assertJsonPath('data.audience_breakdown.neighbourhoods.1.name', 'Plateau')
        ->assertJsonPath('data.audience_breakdown.neighbourhoods.1.share', 0.25);

    $resShow->assertJsonPath('data.audience_breakdown.age_bands.0.min', 25)
        ->assertJsonPath('data.audience_breakdown.age_bands.0.max', 34)
        ->assertJsonPath('data.audience_breakdown.age_bands.0.share', 0.75)
        ->assertJsonPath('data.audience_breakdown.age_bands.1.min', 35)
        ->assertJsonPath('data.audience_breakdown.age_bands.1.max', 44)
        ->assertJsonPath('data.audience_breakdown.age_bands.1.share', 0.25);

    // Vérifier aussi la liste GET /api/boost/campaigns
    $resIndex = $this->getJson('/api/boost/campaigns');
    $resIndex->assertOk()
        ->assertJsonPath('data.0.thumbnail_url', 'https://res.cloudinary.com/bango/image/upload/v1234/test_post_thumb.jpg')
        ->assertJsonPath('data.0.audience_breakdown.neighbourhoods.0.name', 'Almadies');
});

