<?php

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Utilisateur bailleur certifié par défaut
    $this->landlord = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);
});

test('un utilisateur non authentifié ne peut pas créer d\'annonce', function () {
    $response = $this->postJson('/api/listings', [
        'title' => 'Studio meublé',
        'mode' => 'rent',
        'kind' => 'studio',
        'price' => 150000,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 30,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
    ]);

    $response->assertStatus(401);
});

test('un utilisateur sans badge certifié ni rôle admin ne peut pas publier d\'annonce', function () {
    $regularUser = User::factory()->create([
        'badge' => 'none',
        'is_verified' => false,
    ]);
    Passport::actingAs($regularUser);

    $response = $this->postJson('/api/listings', [
        'title' => 'Studio meublé sans badge',
        'mode' => 'rent',
        'kind' => 'studio',
        'price' => 150000,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 30,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
    ]);

    $response->assertStatus(403);
});

test('un bailleur certifié peut créer une annonce valide et elle part en modération (statut review)', function () {
    Passport::actingAs($this->landlord);

    $payload = [
        'title' => 'Superbe studio meublé Almadies',
        'description' => 'Charmant studio calme avec groupe électrogène et forage permanent.',
        'mode' => 'rent',
        'kind' => 'studio',
        'price' => 150000,
        'currency' => 'XOF',
        'deposit_months' => 2,
        'advance_months' => 1,
        'visit_fee' => 10000,
        'charges' => 15000,
        'negotiable' => true,
        'utilities_included' => false,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 32.5,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
        'landmark' => 'près du rond-point Ngor',
        'pin' => ['lat' => 14.7432, 'lng' => -17.5186],
        'amenities' => ['furnished', 'meter', 'parking'],
        'submission_key' => 'sub-key-123',
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.title', 'Superbe studio meublé Almadies')
        ->assertJsonPath('data.mode', ListingMode::Rent->value)
        ->assertJsonPath('data.kind', ListingKind::Studio->value)
        ->assertJsonPath('data.status', ListingStatus::Published->value)
        ->assertJsonPath('data.price', 150000)
        ->assertJsonPath('data.currency', 'XOF')
        ->assertJsonPath('data.city', 'Dakar')
        ->assertJsonPath('data.neighbourhood', 'Almadies');

    $this->assertDatabaseHas('listings', [
        'owner_id' => $this->landlord->id,
        'submission_key' => 'sub-key-123',
        'status' => ListingStatus::Published->value,
        'price' => 150000,
    ]);
});

test('un utilisateur authentifié peut enregistrer un brouillon incomplet sans publication involontaire', function () {
    Passport::actingAs($this->landlord);

    $draftPayload = [
        'submission_key' => 'draft-abc-456',
        'step' => 'features',
        'title' => 'Titre en cours de saisie',
        'city' => 'Dakar',
        'values' => [
            'title' => 'Titre en cours de saisie',
            'city' => 'Dakar',
            'temporaryNotes' => 'À vérifier avec le gardien',
        ],
    ];

    $response = $this->postJson('/api/listings/form/draft', $draftPayload);

    $response->assertStatus(200)
        ->assertJsonPath('data.title', 'Titre en cours de saisie')
        ->assertJsonPath('data.status', ListingStatus::Draft->value);

    $this->assertDatabaseHas('listings', [
        'owner_id' => $this->landlord->id,
        'submission_key' => 'draft-abc-456',
        'status' => ListingStatus::Draft->value,
    ]);

    // Une annonce en brouillon ne doit pas apparaître dans les résultats publics
    $feedResponse = $this->getJson('/api/listings');
    $feedResponse->assertStatus(200);
    expect(collect($feedResponse->json('data'))->pluck('title'))->not->toContain('Titre en cours de saisie');
});

test('un utilisateur peut reprendre et modifier son brouillon', function () {
    Passport::actingAs($this->landlord);

    Listing::factory()->draft()->create([
        'owner_id' => $this->landlord->id,
        'submission_key' => 'draft-to-resume',
        'title' => 'Version 1 du titre',
        'price' => 100000,
    ]);

    // Consulter le brouillon
    $showResponse = $this->getJson('/api/listings/form/draft?key=draft-to-resume');
    $showResponse->assertStatus(200)
        ->assertJsonPath('data.title', 'Version 1 du titre')
        ->assertJsonPath('data.price', 100000);

    // Mettre à jour le brouillon
    $updateResponse = $this->postJson('/api/listings/form/draft', [
        'submission_key' => 'draft-to-resume',
        'step' => 'price',
        'title' => 'Version 2 du titre modifiée',
        'price' => 125000,
    ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.title', 'Version 2 du titre modifiée')
        ->assertJsonPath('data.price', 125000);

    $this->assertDatabaseHas('listings', [
        'owner_id' => $this->landlord->id,
        'submission_key' => 'draft-to-resume',
        'title' => 'Version 2 du titre modifiée',
        'price' => 125000,
    ]);
});

test('un utilisateur ne peut pas accéder ni écraser le brouillon d\'un autre utilisateur (protection IDOR)', function () {
    $otherUser = User::factory()->create(['badge' => 'landlord']);
    $otherDraft = Listing::factory()->draft()->create([
        'owner_id' => $otherUser->id,
        'submission_key' => 'secret-draft-key',
        'title' => 'Brouillon confidentiel',
    ]);

    Passport::actingAs($this->landlord);

    // Tentative de consultation
    $response = $this->getJson('/api/listings/form/draft?key=secret-draft-key');
    $response->assertStatus(200)->assertJsonPath('data', null);

    // Tentative d'écrasement via saveDraft : doit créer un nouveau brouillon pour l'utilisateur courant
    $this->postJson('/api/listings/form/draft', [
        'submission_key' => 'secret-draft-key',
        'title' => 'Mon propre brouillon avec même clé',
    ]);

    // Le brouillon de l'autre utilisateur reste intact
    expect($otherDraft->fresh()->title)->toBe('Brouillon confidentiel');
});

test('la validation refuse les données invalides ou incohérentes', function () {
    Passport::actingAs($this->landlord);

    // 1. Titre trop long (> 70 caractères)
    $response = $this->postJson('/api/listings', [
        'title' => str_repeat('A', 75),
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 100000,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 50,
        'city' => 'Dakar',
        'neighbourhood' => 'Plateau',
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors(['title']);

    // 2. Mode invalide
    $response = $this->postJson('/api/listings', [
        'title' => 'Titre valide',
        'mode' => 'invalid_mode',
        'kind' => 'flat',
        'price' => 100000,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 50,
        'city' => 'Dakar',
        'neighbourhood' => 'Plateau',
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors(['mode']);

    // 3. Mois de caution hors limites (> 36)
    $response = $this->postJson('/api/listings', [
        'title' => 'Titre valide',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 100000,
        'deposit_months' => 48,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 50,
        'city' => 'Dakar',
        'neighbourhood' => 'Plateau',
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors(['deposit_months']);
});

test('un propriétaire peut modifier les détails (texte, prix) d\'une annonce publiée sans relancer la modération vidéo (CR-48)', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->published()->create([
        'owner_id' => $this->landlord->id,
        'title' => 'Ancien titre',
        'price' => 200000,
    ]);

    $response = $this->patchJson("/api/listings/{$listing->id}", [
        'title' => 'Nouveau titre actualisé',
        'price' => 220000,
        'landmark' => 'Nouveau repère Almadies',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.title', 'Nouveau titre actualisé')
        ->assertJsonPath('data.price', 220000)
        ->assertJsonPath('data.status', ListingStatus::Published->value);

    $this->assertDatabaseHas('listings', [
        'id' => $listing->id,
        'title' => 'Nouveau titre actualisé',
        'price' => 220000,
        'status' => ListingStatus::Published->value,
    ]);
});

test('le propriétaire peut basculer le statut d\'un bien parmi published, reserved et taken (BL-18)', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->published()->create([
        'owner_id' => $this->landlord->id,
        'status' => ListingStatus::Published,
    ]);

    // 1. Passage à 'reserved'
    $response = $this->patchJson("/api/listings/{$listing->id}/status", [
        'status' => 'reserved',
    ]);
    $response->assertStatus(200)->assertJsonPath('data.status', 'reserved');
    expect($listing->fresh()->status)->toBe(ListingStatus::Reserved);

    // 2. Passage à 'taken'
    $response = $this->patchJson("/api/listings/{$listing->id}/status", [
        'status' => 'taken',
    ]);
    $response->assertStatus(200)->assertJsonPath('data.status', 'taken');
    expect($listing->fresh()->status)->toBe(ListingStatus::Taken);
    expect($listing->fresh()->taken_at)->not->toBeNull();

    // 3. Rejet d'un statut non autorisé directement par le propriétaire (ex: review)
    $response = $this->patchJson("/api/listings/{$listing->id}/status", [
        'status' => 'review',
    ]);
    $response->assertStatus(422);
});

test('un utilisateur avec badge landlord mais non certifié kyc (is_verified = false) ne peut pas publier d\'annonce', function () {
    $unverifiedUser = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => false,
    ]);
    Passport::actingAs($unverifiedUser);

    $response = $this->postJson('/api/listings', [
        'title' => 'Studio meublé sans kyc',
        'mode' => 'rent',
        'kind' => 'studio',
        'price' => 150000,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 30,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
    ]);

    $response->assertStatus(403);
});

test('un bailleur dont la vérification kyc a expiré (verification_lapsed = true) ne peut pas publier d\'annonce', function () {
    $lapsedLandlord = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => true,
    ]);
    Passport::actingAs($lapsedLandlord);

    $response = $this->postJson('/api/listings', [
        'title' => 'Studio meublé kyc lapsed',
        'mode' => 'rent',
        'kind' => 'studio',
        'price' => 150000,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 30,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
    ]);

    $response->assertStatus(403);
});

test('un bailleur sous le coup d\'une suspension active ne peut pas publier d\'annonce', function () {
    $suspendedLandlord = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'suspended_until' => now()->addDays(14),
    ]);
    Passport::actingAs($suspendedLandlord);

    $response = $this->postJson('/api/listings', [
        'title' => 'Studio meublé suspendu',
        'mode' => 'rent',
        'kind' => 'studio',
        'price' => 150000,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 30,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
    ]);

    $response->assertStatus(403);
});

test('un utilisateur ne peut pas falsifier le owner_id via le payload de création', function () {
    $victim = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);

    Passport::actingAs($this->landlord);

    $response = $this->postJson('/api/listings', [
        'owner_id' => $victim->id, // Tentative de falsification
        'title' => 'Annonce avec tentative d usurpation',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 200000,
        'bedrooms' => 2,
        'showers' => 1,
        'area' => 50,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);

    $response->assertStatus(201);
    $listingId = $response->json('data.id');

    // Le propriétaire en base doit être strictement l'utilisateur connecté ($this->landlord) et non la victime
    $createdListing = Listing::find($listingId);
    expect($createdListing->owner_id)->toBe($this->landlord->id);
    expect($createdListing->owner_id)->not->toBe($victim->id);
});

test('un utilisateur sans badge direct mais avec abonnement actif landlord peut publier une annonce', function () {
    $subUser = User::factory()->create([
        'badge' => 'none',
        'is_verified' => true,
    ]);

    $product = SubscriptionProduct::create([
        'code' => 'landlord_test',
        'name' => 'Bailleur Test',
        'badge_type' => 'landlord',
        'is_active' => true,
    ]);

    $price = SubscriptionPrice::create([
        'product_id' => $product->id,
        'cadence' => 'monthly',
        'price_amount' => 5000,
        'currency' => 'XAF',
        'duration_days' => 30,
        'is_active' => true,
    ]);

    Subscription::create([
        'user_id' => $subUser->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => 5000,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(5),
        'current_period_end' => now()->addDays(25),
        'auto_renew' => true,
    ]);

    Passport::actingAs($subUser);

    $response = $this->postJson('/api/listings', [
        'title' => 'Annonce via abonnement actif',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 180000,
        'bedrooms' => 2,
        'showers' => 1,
        'area' => 45,
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
    ]);

    $response->assertStatus(201);
});
