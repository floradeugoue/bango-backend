<?php

namespace Tests\Feature\Admin\Listing;

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Boost\Campaign;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingMerge;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createListingControlSession(string $roleSlug = 'verification'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::where('email', 'solange.bikoi@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => "op.listings.{$roleSlug}.".uniqid().'@bango.app',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    $session = AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$operator, $sessionId, $csrfToken, $session];
}

it('denies access to listings control without admin session', function () {
    $response = $this->getJson('/api/admin/listings');
    $response->assertStatus(401);
});

it('denies access to operator without listings:view permission', function () {
    $role = Role::where('slug', 'finance')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'fin.test.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator->id,
        'csrf_token' => bin2hex(random_bytes(32)),
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/listings');

    $response->assertStatus(403)
        ->assertJsonFragment(['code' => 'ADMIN_PERMISSION_DENIED']);
});

it('allows Solange Bikoi to list real estate listings with filters and pagination', function () {
    [$solange, $sessionId] = createListingControlSession('verification');

    $owner = User::factory()->create(['name' => 'Bailleur Plateau']);
    $listing1 = Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Bel appartement à Dakar Plateau',
        'city' => 'Dakar',
        'neighbourhood' => 'Plateau',
        'kind' => ListingKind::Flat,
        'status' => ListingStatus::Published,
        'price' => 250000,
    ]);

    $listing2 = Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Villa avec jardin à Saly',
        'city' => 'Mbour',
        'neighbourhood' => 'Saly',
        'kind' => ListingKind::Villa,
        'status' => ListingStatus::Draft,
        'price' => 600000,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/listings?city=Dakar&status=published');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => [
                'current_page',
                'total',
            ],
        ])
        ->assertJsonPath('data.0.id', $listing1->id);
});

it('shows complete listing detail with owner, agent, and control history', function () {
    [$solange, $sessionId] = createListingControlSession('verification');

    $owner = User::factory()->create(['name' => 'Propriétaire Fann']);
    $agent = User::factory()->create(['name' => 'Agent Agréé Fann']);

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'agent_id' => $agent->id,
        'title' => 'Duplex moderne Fann Résidence',
        'price' => 450000,
        'status' => ListingStatus::Published,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/listings/{$listing->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $listing->id)
        ->assertJsonPath('data.owner.id', $owner->id)
        ->assertJsonPath('data.agent.id', $agent->id);
});

it('detects potential duplicate listings based on location, kind and 85% price ratio', function () {
    [$solange, $sessionId] = createListingControlSession('verification');

    $owner1 = User::factory()->create();
    $owner2 = User::factory()->create();

    // Listing 1: 100 000 XOF in Almadies
    $listingA = Listing::factory()->create([
        'owner_id' => $owner1->id,
        'created_by_id' => $owner1->id,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
        'kind' => ListingKind::Flat,
        'price' => 100000,
        'bedrooms' => 2,
        'status' => ListingStatus::Published,
    ]);

    // Listing 2: 90 000 XOF in Almadies (ratio = 90000/100000 = 0.90 >= 0.85 threshold)
    $listingB = Listing::factory()->create([
        'owner_id' => $owner2->id,
        'created_by_id' => $owner2->id,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
        'kind' => ListingKind::Flat,
        'price' => 90000,
        'bedrooms' => 2,
        'status' => ListingStatus::Published,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/listings/duplicates');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $data = $response->json('data');
    expect(count($data))->toBeGreaterThanOrEqual(1);

    $foundPair = collect($data)->first(function ($pair) use ($listingA, $listingB) {
        return ($pair['primary']['id'] === $listingA->id && $pair['duplicate']['id'] === $listingB->id)
            || ($pair['primary']['id'] === $listingB->id && $pair['duplicate']['id'] === $listingA->id);
    });

    expect($foundPair)->not->toBeNull()
        ->and($foundPair['price_similarity'])->toBeGreaterThanOrEqual(85.0);
});

it('merges duplicate listings transactionally and depublishes secondary listing', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $owner = User::factory()->create();
    $primary = Listing::factory()->create(['owner_id' => $owner->id, 'status' => ListingStatus::Published]);
    $secondary = Listing::factory()->create(['owner_id' => $owner->id, 'status' => ListingStatus::Published]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/listings/duplicates/merge', [
            'primary_ref' => $primary->id,
            'secondary_ref' => $secondary->id,
            'reason' => 'Doublon confirmé par la vérificatrice KYC',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.primary_id', $primary->id);

    // Primary remains published, secondary becomes draft (unpublished)
    $primary->refresh();
    $secondary->refresh();
    expect($primary->status)->toBe(ListingStatus::Published)
        ->and($secondary->status)->toBe(ListingStatus::Draft);

    // Assert merge record exists
    $this->assertDatabaseHas('listing_merges', [
        'primary_listing_id' => $primary->id,
        'secondary_listing_id' => $secondary->id,
        'operator_id' => $solange->id,
    ]);
});

it('prevents repeated or circular merge on already merged listing', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $owner = User::factory()->create();
    $primary = Listing::factory()->create(['owner_id' => $owner->id]);
    $secondary = Listing::factory()->create(['owner_id' => $owner->id]);

    ListingMerge::create([
        'primary_listing_id' => $primary->id,
        'secondary_listing_id' => $secondary->id,
        'operator_id' => $solange->id,
        'reason' => 'Déjà fusionné',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/listings/duplicates/merge', [
            'primary_ref' => $primary->id,
            'secondary_ref' => $secondary->id,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'MERGE_INVALID');
});

it('dismisses false positive duplicate pair and saves decision', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $listingA = Listing::factory()->create();
    $listingB = Listing::factory()->create();

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listingA->id}/not-duplicate", [
            'comparison_ref' => $listingB->id,
            'reason' => 'Deux appartements distincts dans le même immeuble',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('listing_duplicate_dismissals', [
        'operator_id' => $solange->id,
        'reason' => 'Deux appartements distincts dans le même immeuble',
    ]);
});

it('reviews listing: approves and publishes pending listing', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $listing = Listing::factory()->create([
        'status' => ListingStatus::Review,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listing->id}/review", [
            'decision' => 'approve',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'published');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Published)
        ->and($listing->published_at)->not->toBeNull();

    $this->assertDatabaseHas('listing_review_logs', [
        'listing_id' => $listing->id,
        'operator_id' => $solange->id,
        'action' => 'approve',
        'to_status' => 'published',
    ]);
});

it('reviews listing: rejects listing with mandatory reason', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $listing = Listing::factory()->create([
        'status' => ListingStatus::Review,
    ]);

    // Missing reason should fail
    $bad = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listing->id}/review", [
            'decision' => 'reject',
        ]);
    $bad->assertStatus(422);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listing->id}/review", [
            'decision' => 'reject',
            'reason' => 'Photos trompeuses ne correspondant pas à l\'adresse',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Rejected)
        ->and($listing->rejection_reason)->toBe('Photos trompeuses ne correspondant pas à l\'adresse');
});

it('bulk reviews multiple listings with individual outcomes', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $l1 = Listing::factory()->create(['status' => ListingStatus::Review]);
    $l2 = Listing::factory()->create(['status' => ListingStatus::Review]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/listings/review/bulk', [
            'refs' => [$l1->id, $l2->id, 'invalid-uuid-ref'],
            'decision' => 'approve',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $results = $response->json('data');
    expect($results)->toHaveCount(3);

    // Two success, one failed
    $successes = collect($results)->where('status', 'success');
    $fails = collect($results)->where('status', 'failed');
    expect($successes)->toHaveCount(2)
        ->and($fails)->toHaveCount(1);
});

it('certifies on-site visit by an authorized agent', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $listing = Listing::factory()->create([
        'agent_visited' => false,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listing->id}/agent-visit", [
            'notes' => 'Visite effectuée le matin avec vérification du compteur d\'eau et des clés',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.agent_visited', true);

    $listing->refresh();
    expect($listing->agent_visited)->toBeTrue();

    $this->assertDatabaseHas('listing_agent_certifications', [
        'listing_id' => $listing->id,
        'agent_id' => $solange->id,
    ]);
});

it('unpublishes and republishes a listing through both direct and alias routes', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $listing = Listing::factory()->create(['status' => ListingStatus::Published]);

    // 1. Unpublish
    $unpub = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listing->id}/unpublish", [
            'reason' => 'Mise aux normes en cours',
        ]);

    $unpub->assertStatus(200)
        ->assertJsonPath('data.status', 'draft');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Draft);

    // 2. Republish via direct route /listings/{ref}/republish
    $repub1 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listing->id}/republish", [
            'reason' => 'Normes validées',
        ]);

    $repub1->assertStatus(200)
        ->assertJsonPath('data.status', 'published');

    // 3. Republish via root alias /republish with ref in body
    $listing->update(['status' => ListingStatus::Draft]);

    $repub2 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/republish', [
            'ref' => $listing->id,
            'reason' => 'Remise en ligne administrative',
        ]);

    $repub2->assertStatus(200)
        ->assertJsonPath('data.status', 'published');
});

it('marks property as rented, completes active boost campaigns without refund and cancels pending visits', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    $owner = User::factory()->create();
    $listing = Listing::factory()->create(['owner_id' => $owner->id, 'status' => ListingStatus::Published]);

    // Active boost campaign on this listing
    $campaign = Campaign::create([
        'user_id' => $owner->id,
        'boostable_type' => Listing::class,
        'boostable_id' => $listing->id,
        'objective' => CampaignObjective::Reach->value,
        'budget_amount' => 50000,
        'duration_days' => 5,
        'status' => CampaignStatus::Active->value,
        'spent_amount' => 10000,
        'start_at' => now()->subDays(2),
        'end_at' => now()->addDays(5),
    ]);

    // Pending visit on this listing
    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $owner->id,
        'start_at' => now()->addDay(),
        'end_at' => now()->addDay()->addHour(),
        'capacity' => 1,
        'status' => 'open',
    ]);

    $visit = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => User::factory()->create()->id,
        'owner_id' => $owner->id,
        'status' => VisitReservationStatus::Requested->value,
        'fee' => 5000,
        'currency' => 'XOF',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listing->id}/rented", [
            'reason' => 'Bail signé chez le notaire',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'taken');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Taken);

    // Boost campaign must be stopped / completed
    $campaign->refresh();
    expect($campaign->status)->toBe(CampaignStatus::Completed);

    // Visit reservation must be cancelled
    $visit->refresh();
    expect($visit->status)->toBe(VisitReservationStatus::Cancelled);
});

it('returns administrative map data excluding invalid coordinates', function () {
    [$solange, $sessionId] = createListingControlSession('verification');

    $lWithCoords = Listing::factory()->create([
        'city' => 'Dakar',
        'lat' => 14.6937,
        'lng' => -17.4441,
        'status' => ListingStatus::Published,
    ]);

    $lWithoutCoords = Listing::factory()->create([
        'city' => 'Dakar',
        'lat' => null,
        'lng' => null,
        'status' => ListingStatus::Published,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/listings/map?city=Dakar');

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($lWithCoords->id)
        ->and($ids)->not->toContain($lWithoutCoords->id);
});

it('tracks visits and flags overdue SLA over 18 hours', function () {
    [$solange, $sessionId] = createListingControlSession('verification');

    $owner = User::factory()->create();
    $listing = Listing::factory()->create(['owner_id' => $owner->id]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $owner->id,
        'start_at' => now()->addDays(2),
        'end_at' => now()->addDays(2)->addHour(),
        'capacity' => 1,
        'status' => 'open',
    ]);

    // Overdue visit (created 20h ago without owner answer)
    $overdueVisit = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => User::factory()->create()->id,
        'owner_id' => $owner->id,
        'status' => VisitReservationStatus::Requested->value,
        'fee' => 5000,
        'currency' => 'XOF',
    ]);
    VisitReservation::where('id', $overdueVisit->id)->update(['created_at' => now()->subHours(20)]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/listings/visits?overdue_sla=1');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.id', $overdueVisit->id)
        ->assertJsonPath('data.0.is_overdue_sla_18h', true);
});

it('previews and exports CSV safely with formula injection protection', function () {
    [$solange, $sessionId, $csrfToken] = createListingControlSession('verification');

    // Create listing with malicious title starting with '=' (CSV injection vector)
    $malicious = Listing::factory()->create([
        'title' => '=cmd|"/C calc"!A0',
        'city' => 'Dakar',
        'status' => ListingStatus::Published,
    ]);

    // 1. Preview
    $preview = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/listings/exports/preview', [
            'city' => 'Dakar',
        ]);

    $preview->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'count',
                'columns',
            ],
        ]);

    // 2. Download CSV
    $download = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/listings/exports', [
            'city' => 'Dakar',
        ]);

    $download->assertStatus(200)
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $csvContent = $download->streamedContent();

    // Check Excel UTF-8 BOM
    expect(str_starts_with($csvContent, "\xEF\xBB\xBF"))->toBeTrue();

    // Check neutralization of formula injection (prefixing with ')
    expect($csvContent)->toContain("'=cmd");
});
