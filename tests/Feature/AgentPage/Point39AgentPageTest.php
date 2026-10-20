<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

test('Point 39 : Délégation - relance, modification et rétablissement', function () {
    $landlord = User::factory()->create([
        'badge' => 'bailleur',
        'is_verified' => true,
    ]);
    $agent = User::factory()->create([
        'badge' => 'agent',
        'is_verified' => true,
    ]);
    $listing = Listing::factory()->create(['owner_id' => $landlord->id]);

    Passport::actingAs($landlord);

    // 1. Délégation en attente
    $delegation = Delegation::create([
        'listing_id' => $listing->id,
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'status' => DelegationStatus::Pending,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDays(2),
    ]);

    // Relance
    $remindResponse = $this->postJson("/api/landlord/delegations/{$delegation->id}/remind");
    $remindResponse->assertOk();
    expect($delegation->fresh()->metadata['remind_count'])->toBe(1);

    // Modification
    $updateResponse = $this->putJson("/api/landlord/delegations/{$delegation->id}", [
        'capabilities' => ['visits', 'reply'],
        'expires_at' => now()->addDays(30)->toDateString(),
    ]);
    $updateResponse->assertOk();
    expect($delegation->fresh()->capabilities)->toContain('visits', 'reply');

    // Révocation puis Rétablissement
    $delegation->update([
        'status' => DelegationStatus::Revoked,
        'revoked_at' => now(),
    ]);

    $reinstateResponse = $this->postJson("/api/landlord/delegations/{$delegation->id}/reinstate");
    $reinstateResponse->assertOk();
    expect($delegation->fresh()->status)->toBe(DelegationStatus::Active)
        ->and($delegation->fresh()->revoked_at)->toBeNull();
});

test('Point 39 : Annuaire des agents avec indicateur et filtre activité 30 jours', function () {
    $activeAgent = User::factory()->create([
        'name' => 'Agent Actif',
        'badge' => 'agent',
        'is_verified' => true,
        'city' => 'Douala',
        'updated_at' => now()->subDays(2),
    ]);

    $inactiveAgent = User::factory()->create([
        'name' => 'Agent Inactif',
        'badge' => 'agent',
        'is_verified' => true,
        'city' => 'Douala',
    ]);
    DB::table('users')->where('id', $inactiveAgent->id)->update([
        'updated_at' => now()->subDays(60),
        'created_at' => now()->subDays(60),
    ]);

    // Search directory with active_30d filter
    $response = $this->getJson('/api/search/agents?active_30d=1');
    $response->assertOk();

    $data = $response->json('data');
    $ids = collect($data)->pluck('id')->all();

    expect($ids)->toContain($activeAgent->id)
        ->and($ids)->not->toContain($inactiveAgent->id);

    // Vérifier exposition dans la ressource
    $first = collect($data)->firstWhere('id', $activeAgent->id);
    expect($first)->toHaveKey('is_active_30d')
        ->and($first['is_active_30d'])->toBeTrue();
});

test('Point 39 : Page - abonnés, ville, masquage et attribution boîte de réception', function () {
    $owner = User::factory()->create(['is_verified' => true]);
    $teamMember = User::factory()->create(['is_verified' => true]);
    $follower = User::factory()->create(['is_verified' => true]);

    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Immo Prestige',
        'handle' => 'immo-prestige',
        'sector' => PageSector::RealEstateAgency,
        'status' => PageStatus::Active,
        'city' => 'Yaoundé',
        'is_hidden' => false,
        'followers_count' => 0,
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $owner->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $teamMember->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    // 1. Vérification ville et abonnés dans la ressource
    Passport::actingAs($owner);
    $showResponse = $this->getJson("/api/pages/{$page->handle}");
    $showResponse->assertOk()
        ->assertJsonPath('data.city', 'Yaoundé')
        ->assertJsonPath('data.followers_count', 0)
        ->assertJsonPath('data.is_hidden', false);

    // 2. Abonnement et désabonnement
    Passport::actingAs($follower);
    $followResponse = $this->postJson("/api/pages/{$page->id}/follow");
    $followResponse->assertOk()
        ->assertJsonPath('is_following', true)
        ->assertJsonPath('followers_count', 1);

    expect($page->fresh()->followers_count)->toBe(1);

    $unfollowResponse = $this->deleteJson("/api/pages/{$page->id}/follow");
    $unfollowResponse->assertOk()
        ->assertJsonPath('is_following', false)
        ->assertJsonPath('followers_count', 0);

    // 3. Masquage / Visibilité
    Passport::actingAs($owner);
    $hideResponse = $this->postJson("/api/pages/{$page->id}/visibility", [
        'is_hidden' => true,
    ]);
    $hideResponse->assertOk()
        ->assertJsonPath('data.is_hidden', true);

    expect($page->fresh()->is_hidden)->toBeTrue();

    // 4. Attribution de la boîte de réception à un membre
    $assignResponse = $this->postJson("/api/pages/{$page->id}/inbox-assignment", [
        'user_id' => $teamMember->id,
    ]);
    $assignResponse->assertOk()
        ->assertJsonPath('data.inbox_assigned_to.id', $teamMember->id);

    expect($page->fresh()->inbox_assigned_to_id)->toBe($teamMember->id);
});
