<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Http\Resources\Message\ConversationResource;
use App\Http\Resources\Message\MessageResource;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Delegation\DelegationService;
use App\Services\Message\MessageService;
use App\Services\Message\MessageThreadService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

test('agent with reply access is automatically added to listing conversation and can reply on behalf of landlord', function () {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $prospect = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'status' => 'published',
    ]);

    // Active delegation with reply capability
    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active->value,
        'capabilities' => [DelegationCapability::Reply->value],
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $threadService = app(MessageThreadService::class);
    $messageService = app(MessageService::class);

    // Prospect starts conversation on listing
    $conversation = $threadService->getOrCreateDirectConversation($prospect, $landlord->id, $listing->id);

    // Agent must be a member of the conversation
    expect($conversation->members()->where('user_id', $agent->id)->exists())->toBeTrue();

    // Agent sees conversation in their thread list
    $agentThreads = $threadService->listUserThreads($agent);
    expect($agentThreads->pluck('id'))->toContain($conversation->id);

    // Agent can reply to prospect on behalf of landlord
    $message = $messageService->sendMessage($conversation, $agent, [
        'body' => 'Bonjour, je suis l\'agent mandaté.',
    ]);
    expect($message->sender_id)->toBe($agent->id);

    // Verify on_behalf_of in ConversationResource and MessageResource
    $request = Request::create('/api/messages/threads', 'GET');
    $request->setUserResolver(fn () => $prospect);

    $conversationRes = (new ConversationResource($conversation->fresh()))->toArray($request);
    expect($conversationRes['on_behalf_of'])->not()->toBeNull()
        ->and($conversationRes['on_behalf_of']['agent_id'])->toBe($agent->id);

    $messageRes = (new MessageResource($message->fresh()))->toArray($request);
    expect($messageRes['on_behalf_of'])->not()->toBeNull()
        ->and($messageRes['on_behalf_of']['agent_id'])->toBe($agent->id);

    // Revocation: Agent remains a member (read-only history), but cannot post new messages
    $delegation = Delegation::where('agent_id', $agent->id)->where('listing_id', $listing->id)->first();
    app(DelegationService::class)->revokeDelegation($delegation, $landlord);

    // Agent still sees conversation in thread list
    $agentThreadsAfterRevocation = $threadService->listUserThreads($agent);
    expect($agentThreadsAfterRevocation->pluck('id'))->toContain($conversation->id);

    // But agent cannot send new message (throws 403 AccessDeniedHttpException)
    expect(fn () => $messageService->sendMessage($conversation->fresh(), $agent, [
        'body' => 'Message interdit après révocation',
    ]))->toThrow(AccessDeniedHttpException::class);
});

test('accepting delegation with reply retroactively adds agent to existing listing conversations', function () {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $prospect = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'status' => 'published',
    ]);

    $threadService = app(MessageThreadService::class);

    // Prospect creates conversation before delegation exists
    $conversation = $threadService->getOrCreateDirectConversation($prospect, $landlord->id, $listing->id);
    expect($conversation->members()->where('user_id', $agent->id)->exists())->toBeFalse();

    // Landlord creates invitation
    $delegationService = app(DelegationService::class);
    $delegation = $delegationService->inviteAgent($landlord, [
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'capabilities' => [DelegationCapability::Reply->value, DelegationCapability::Visits->value],
    ]);

    // Agent accepts invitation
    $delegationService->acceptInvitation($delegation, $agent);

    // Agent must now be added to existing listing conversation
    expect($conversation->fresh()->members()->where('user_id', $agent->id)->exists())->toBeTrue();
    expect($threadService->listUserThreads($agent)->pluck('id'))->toContain($conversation->id);
});
