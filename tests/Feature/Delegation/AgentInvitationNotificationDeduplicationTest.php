<?php

namespace Tests\Feature\Delegation;

use App\Enums\Delegation\DelegationStatus;
use App\Models\Delegation\Delegation;
use App\Models\Delegation\VisitShare;
use App\Models\Listing\Listing;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Notifications\Delegation\AgentInvitationNotification;
use App\Notifications\Delegation\VisitShareNegotiationNotification;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $this->landlordPrice = SubscriptionPrice::where('product_id', $this->landlordProduct->id)->where('cadence', 'monthly')->first();

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
});

test('inviting an agent with visit share percentage sends only AgentInvitationNotification, not visit_share_offer', function () {
    Notification::fake();

    $landlord = ($this->createLandlord)(['name' => 'Ibrahim']);
    $agent = User::factory()->create(['name' => 'Sandrine', 'badge' => 'agent', 'is_verified' => true]);
    $listing = Listing::factory()->create(['owner_id' => $landlord->id]);

    Passport::actingAs($landlord);

    // 1. Ibrahim invite Sandrine avec 25% de frais de visite
    $response = $this->postJson('/api/landlord/delegations', [
        'listing_id' => $listing->id,
        'agent_id' => $agent->id,
        'capabilities' => ['visits', 'reply'],
        'visit_share_percent' => 25,
    ]);

    $response->assertStatus(201);
    expect((float) $response->json('data.visit_share_percent'))->toEqual(25.0);

    // 2. Si l'application mobile envoie également la proposition de pourcentage liée à l'invitation
    $offerResponse = $this->postJson('/api/delegations/visit-share', [
        'agent_id' => $agent->id,
        'percent' => 25,
    ]);
    $offerResponse->assertStatus(200);

    // Vérification : Sandrine ne reçoit QUE AgentInvitationNotification (avec le pourcentage)
    Notification::assertSentTo($agent, AgentInvitationNotification::class, function ($notification) {
        $data = $notification->toArray($notification);

        return $data['type'] === 'agent_invitation'
            && (float) $data['visit_share_percent'] === 25.0;
    });

    // visit_share_offer NE DOIT PAS être envoyée lors de l'invitation initiale
    Notification::assertNotSentTo($agent, VisitShareNegotiationNotification::class);
});

test('counter-offer made after the invitation sends visit_share_offer notification', function () {
    Notification::fake();

    $landlord = ($this->createLandlord)(['name' => 'Ibrahim']);
    $agent = User::factory()->create(['name' => 'Sandrine', 'badge' => 'agent', 'is_verified' => true]);
    $listing = Listing::factory()->create(['owner_id' => $landlord->id]);

    // Invitation initiale en attente avec 25%
    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Pending,
        'invited_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    VisitShare::create([
        'landlord_id' => $landlord->id,
        'agent_id' => $agent->id,
        'offer_percent' => 25,
        'offer_by' => 'landlord',
        'offer_at' => now(),
    ]);

    // 1. Sandrine (l'agent) fait une contre-proposition de 35% après coup
    Passport::actingAs($agent);
    $counterResponse = $this->postJson('/api/delegations/visit-share', [
        'bailleur_id' => $landlord->id,
        'percent' => 35,
    ]);
    $counterResponse->assertStatus(200);

    // Ibrahim doit recevoir la notification de contre-proposition visit_share_offer
    Notification::assertSentTo($landlord, VisitShareNegotiationNotification::class, function ($notification) {
        $data = $notification->toArray($notification);

        return $data['type'] === 'visit_share_offer'
            && (float) $data['offer_percent'] === 35.0
            && $data['offer_by'] === 'agent';
    });

    // 2. Ibrahim répond à la contre-proposition en proposant 30%
    Passport::actingAs($landlord);
    $response2 = $this->postJson('/api/delegations/visit-share', [
        'agent_id' => $agent->id,
        'percent' => 30,
    ]);
    $response2->assertStatus(200);

    // Sandrine doit cette fois-ci recevoir la contre-proposition du bailleur
    Notification::assertSentTo($agent, VisitShareNegotiationNotification::class, function ($notification) {
        $data = $notification->toArray($notification);

        return $data['type'] === 'visit_share_offer'
            && (float) $data['offer_percent'] === 30.0
            && $data['offer_by'] === 'landlord';
    });
});
