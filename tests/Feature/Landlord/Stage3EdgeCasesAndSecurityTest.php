<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Enums\Kyc\KycDocKind;
use App\Enums\Kyc\KycStatus;
use App\Models\Delegation\Delegation;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycSubmission;
use App\Models\Listing\Listing;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Listing\ListingService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $this->landlordPrice = SubscriptionPrice::where('product_id', $this->landlordProduct->id)->where('cadence', 'monthly')->first();

    $this->agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $this->agentPrice = SubscriptionPrice::where('product_id', $this->agentProduct->id)->where('cadence', 'monthly')->first();

    $this->createLandlord = function (array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'badge' => 'landlord',
            'is_verified' => true,
            'verification_lapsed' => false,
        ], $attributes));

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

        return $user;
    };

    $this->createAgent = function (array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'badge' => 'agent',
            'is_verified' => true,
            'verification_lapsed' => false,
        ], $attributes));

        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $this->agentProduct->id,
            'price_id' => $this->agentPrice->id,
            'badge_type' => 'agent',
            'cadence' => 'monthly',
            'amount' => $this->agentPrice->price_amount,
            'currency' => 'XAF',
            'state' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        return $user;
    };
});

test('EC-4: Agent cannot edit listing without edit capability', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value], // Only visits, NO edit
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(5),
    ]);

    $listingService = app(ListingService::class);
    expect($listingService->canEditListing($agent, $listing))->toBeFalse();
    expect($listingService->canManageVisits($agent, $listing))->toBeTrue();
});

test('EC-5: Agent can reply messages only with reply capability', function (): void {
    $landlord = ($this->createLandlord)();
    $agentWithReply = ($this->createAgent)();
    $agentWithoutReply = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agentWithReply->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Reply->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(5),
    ]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agentWithoutReply->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(5),
    ]);

    $listingService = app(ListingService::class);
    expect($listingService->canReplyMessages($agentWithReply, $listing))->toBeTrue();
    expect($listingService->canReplyMessages($agentWithoutReply, $listing))->toBeFalse();
});

test('EC-8 & EC-23: When agent badge expires or lapses active delegation access is disabled', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(5),
    ]);

    // Now agent's verification lapses
    $agent->verification_lapsed = true;
    $agent->save();

    Passport::actingAs($agent);

    // Agent middleware blocks request
    $response = $this->getJson('/api/agent/properties');
    $response->assertStatus(403)
        ->assertJson(['code' => 'KYC_LAPSED']);
});

test('EC-13: Invitation cannot be accepted after revocation', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Revoked,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDays(2),
        'revoked_at' => now()->subDay(),
        'revoked_by_id' => $landlord->id,
    ]);

    Passport::actingAs($agent);

    $response = $this->postJson("/api/agent/invitations/{$delegation->id}/accept");
    $response->assertStatus(422);
});

test('EC-16: KYC private document cannot be downloaded by another user', function (): void {
    Storage::fake('local');

    $owner = User::factory()->create();
    $hacker = User::factory()->create();

    $submission = KycSubmission::create([
        'user_id' => $owner->id,
        'status' => KycStatus::Pending,
        'full_name' => $owner->name,
    ]);

    $file = UploadedFile::fake()->create('cni.jpg', 400, 'image/jpeg');
    $path = "kyc/{$owner->id}/cni_test.jpg";
    Storage::disk('local')->put($path, $file->getContent());

    $document = KycDocument::create([
        'kyc_submission_id' => $submission->id,
        'user_id' => $owner->id,
        'kind' => KycDocKind::Front,
        'file_path' => $path,
        'original_name' => 'cni.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 400,
        'status' => 'pending',
    ]);

    // Unauthorized user attempts download -> 403
    Passport::actingAs($hacker);
    $responseFail = $this->getJson("/api/landlord/kyc/documents/{$document->id}");
    $responseFail->assertStatus(403);

    // Legitimate owner downloads -> 200 stream
    Passport::actingAs($owner);
    $responseOk = $this->getJson("/api/landlord/kyc/documents/{$document->id}");
    $responseOk->assertOk();
});

test('EC-20: Mass assignment prevention on delegations', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $attacker = User::factory()->create();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    Passport::actingAs($landlord);

    // Attacker tries to inject bailleur_id of someone else or force status=active
    $response = $this->postJson('/api/landlord/delegations', [
        'listing_id' => $listing->id,
        'agent_id' => $agent->id,
        'bailleur_id' => $attacker->id,
        'status' => 'active',
        'capabilities' => ['visits'],
    ]);

    $response->assertStatus(201);
    $delegation = Delegation::find($response->json('data.id'));

    // Server-enforced values: bailleur_id is the authenticated user, status is pending
    expect($delegation->bailleur_id)->toBe($landlord->id);
    expect($delegation->status)->toBe(DelegationStatus::Pending);
});

test('EC-21: Expired invitation cannot be accepted', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Pending,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDays(10),
        'expires_at' => now()->subHour(), // Expired
    ]);

    Passport::actingAs($agent);

    $response = $this->postJson("/api/agent/invitations/{$delegation->id}/accept");
    $response->assertStatus(422);
});

test('EC-22: Landlord subscription expired blocks access to landlord dashboard', function (): void {
    $landlord = ($this->createLandlord)();

    // Expire landlord subscription
    Subscription::where('user_id', $landlord->id)->update([
        'state' => 'expired',
        'current_period_end' => now()->subDay(),
    ]);

    Passport::actingAs($landlord);

    $response = $this->getJson('/api/landlord/properties');
    $response->assertStatus(403)
        ->assertJson(['code' => 'SUBSCRIPTION_REQUIRED']);
});
