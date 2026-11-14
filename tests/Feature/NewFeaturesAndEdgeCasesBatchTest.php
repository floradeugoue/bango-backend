<?php

use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingAlert;
use App\Models\Message\Conversation;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Payment\PaymentTransaction;
use App\Models\Support\AppFeedback;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTO\GatewayInitiateResult;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PricingService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create(['name' => 'Marie']);
    $this->peer = User::factory()->create(['name' => 'Nadège']);

    $this->page = Page::factory()->create([
        'owner_id' => $this->user->id,
        'name' => 'Marie Shop',
        'handle' => 'marie-shop',
    ]);

    PageMember::create([
        'page_id' => $this->page->id,
        'user_id' => $this->user->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);
});

test('point 1: conversation ouverte avec X-Bango-Page prend page_id et reste visible dans la boite de la page', function (): void {
    Passport::actingAs($this->user);

    $res = $this->withHeader('X-Bango-Page', (string) $this->page->id)
        ->postJson('/api/messages/threads', [
            'peer_id' => $this->peer->id,
        ]);

    $res->assertStatus(201)
        ->assertJsonPath('data.page_id', $this->page->id);

    $threadId = $res->json('data.id');
    $conversation = Conversation::find($threadId);
    expect($conversation->page_id)->toBe($this->page->id);

    // Visible dans GET /api/messages/threads avec X-Bango-Page
    $threadsRes = $this->withHeader('X-Bango-Page', (string) $this->page->id)
        ->getJson('/api/messages/threads');

    $threadsRes->assertStatus(200);
    $threadIds = collect($threadsRes->json('data'))->pluck('id')->all();
    expect($threadIds)->toContain($threadId);
});

test('point 2: echec immediat monetbil passe la transaction en failed et libere le creneau de visite', function (): void {
    Passport::actingAs($this->user);

    $listing = Listing::factory()->create(['owner_id' => $this->peer->id]);
    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $this->peer->id,
        'creator_id' => $this->peer->id,
        'start_at' => now()->addDays(2),
        'end_at' => now()->addDays(2)->addHour(),
        'capacity' => 1,
        'status' => 'open',
    ]);

    $reservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'owner_id' => $this->peer->id,
        'slot_id' => $slot->id,
        'prospect_id' => $this->user->id,
        'status' => VisitReservationStatus::PendingPayment,
        'fee_amount' => 5000,
        'lock_expires_at' => now()->addMinutes(15),
    ]);

    $quote = app(PricingService::class)->calculateQuote(
        serviceType: PaymentServiceType::Visit,
        customAmount: 5000
    );

    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'reference' => 'TX-TEST-FAIL-01',
        'idempotency_key' => 'IDEMP-FAIL-01',
        'service_type' => PaymentServiceType::Visit,
        'payable_type' => VisitReservation::class,
        'payable_id' => $reservation->id,
        'amount' => $quote->amount,
        'fee_amount' => $quote->feeAmount,
        'tax_amount' => $quote->taxAmount,
        'total_amount' => $quote->totalAmount,
        'currency' => 'XAF',
        'provider' => 'monetbil',
        'channel' => 'mobile_money',
        'status' => PaymentStatus::Pending,
    ]);

    $reservation->payment_transaction_id = $tx->id;
    $reservation->save();

    // Mock MonetbilGateway pour renvoyer un échec immédiat
    $mockGateway = Mockery::mock(PaymentGatewayInterface::class);
    $mockGateway->shouldReceive('initiatePayment')
        ->once()
        ->andReturn(new GatewayInitiateResult(
            isSuccessful: false,
            gatewayReference: null,
            errorCode: 'OPERATOR_REJECTED',
            errorMessage: 'Solde insuffisant ou rejet opérateur'
        ));

    $gatewayManager = app(PaymentGatewayManager::class);
    $gatewayManager->extend('monetbil', fn () => $mockGateway);

    $res = $this->postJson('/api/payments/initiate', [
        'transaction_id' => $tx->id,
        'idempotency_key' => 'IDEMP-FAIL-01',
        'service_type' => 'visit',
        'payable_type' => 'visit_reservation',
        'payable_id' => $reservation->id,
        'custom_amount' => 5000,
        'phone' => '+237699000000',
        'operator' => 'orange',
    ]);

    $res->assertStatus(400)
        ->assertJsonPath('error.code', 'PAYMENT_REFUSED');

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Failed)
        ->and($tx->metadata['failure_reason'])->toContain('Solde insuffisant');

    $reservation->refresh();
    expect($reservation->status)->toBe(VisitReservationStatus::Expired);
});

test('point 3: commande alerts:pause-dormant met en pause les alertes sans match depuis 90 jours', function (): void {
    $activeAlert = ListingAlert::create([
        'user_id' => $this->user->id,
        'name' => 'Alerte Active',
        'city' => 'Douala',
        'is_active' => true,
        'last_matched_at' => now()->subDays(10),
    ]);

    $dormantAlert = ListingAlert::create([
        'user_id' => $this->user->id,
        'name' => 'Alerte Dormante',
        'city' => 'Yaoundé',
        'is_active' => true,
        'last_matched_at' => now()->subDays(95),
    ]);

    Artisan::call('alerts:pause-dormant');

    $activeAlert->refresh();
    $dormantAlert->refresh();

    expect($activeAlert->is_active)->toBeTrue();
    expect($dormantAlert->is_active)->toBeFalse();
    expect($dormantAlert->paused_reason)->toBe('no_match_90d');
});

test('point 3: commande support:auto-close-waiting clôture les tickets en attente sans réponse depuis 5 jours', function (): void {
    $ticket = SupportTicket::create([
        'user_id' => $this->user->id,
        'reference' => 'TCK-2026-999',
        'subject' => 'Problème de test',
        'category' => TicketCategory::Other,
        'priority' => TicketPriority::Normal,
        'status' => TicketStatus::WaitingForUser,
    ]);

    $ticket->updated_at = now()->subDays(6);
    $ticket->saveQuietly();

    Artisan::call('support:auto-close-waiting');

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->closed_at)->not->toBeNull();

    // Toujours rouvrable par POST /support/tickets/{id}/reopen
    Passport::actingAs($this->user);
    $reopenRes = $this->postJson("/api/support/tickets/{$ticket->id}/reopen", [
        'reason' => 'Le problème est réapparu.',
    ]);

    $reopenRes->assertStatus(200);
    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
});

test('point 4: feedback enregistré via POST /api/app-feedback', function (): void {
    Passport::actingAs($this->user);

    $res = $this->postJson('/api/app-feedback', [
        'mood' => 'great',
        'comment' => 'Application très fluide et intuitive !',
        'platform' => 'android',
        'app_version' => '2.1.0',
    ]);

    $res->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.mood', 'great');

    expect(AppFeedback::where('user_id', $this->user->id)->where('mood', 'great')->exists())->toBeTrue();
});
