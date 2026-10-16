<?php

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketMessageSenderType;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Auth\Role;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Notifications\Support\SupportTicketReplyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Notification::fake();

    $supportRole = Role::firstOrCreate(['slug' => 'support'], ['name' => 'Support']);
    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);

    $this->agent = User::factory()->create(['name' => 'Agent Dupont', 'role_id' => $supportRole->id]);
    $this->admin = User::factory()->create(['name' => 'Admin Superieur', 'role_id' => $adminRole->id]);
    $this->client = User::factory()->create(['name' => 'Client Normal']);
});

test('GET /api/admin/support/tickets permet à un agent ou admin de lister les tickets et bloque les clients', function () {
    SupportTicket::create([
        'reference' => 'TIC-2026-ADM01',
        'user_id' => $this->client->id,
        'subject' => 'Ticket A',
        'category' => TicketCategory::AccountAuth,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::High,
    ]);

    SupportTicket::create([
        'reference' => 'TIC-2026-ADM02',
        'user_id' => $this->client->id,
        'subject' => 'Ticket B',
        'category' => TicketCategory::PaymentWallet,
        'status' => TicketStatus::Resolved,
        'priority' => TicketPriority::Normal,
    ]);

    // 1. Accès refusé pour un utilisateur standard
    Passport::actingAs($this->client);
    $this->getJson('/api/admin/support/tickets')->assertForbidden();

    // 2. Accès accordé pour l'admin avec filtrage
    Passport::actingAs($this->admin);
    $response = $this->getJson('/api/admin/support/tickets?status='.TicketStatus::Open->value);

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'TIC-2026-ADM01');
});

test('PATCH /api/admin/support/tickets/{id}/status permet de modifier le statut d\'un ticket', function () {
    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-STAT01',
        'user_id' => $this->client->id,
        'subject' => 'Ticket en cours',
        'category' => TicketCategory::Other,
        'status' => TicketStatus::Open,
    ]);

    // Client normal : interdit
    Passport::actingAs($this->client);
    $this->patchJson("/api/admin/support/tickets/{$ticket->id}/status", [
        'status' => TicketStatus::Resolved->value,
    ])->assertForbidden();

    // Admin : succès
    Passport::actingAs($this->admin);
    $response = $this->patchJson("/api/admin/support/tickets/{$ticket->id}/status", [
        'status' => TicketStatus::Resolved->value,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status.value', TicketStatus::Resolved->value);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->fresh()->resolved_at)->not->toBeNull();
});

test('PATCH /api/admin/support/tickets/{id}/assign permet d\'assigner un ticket à un agent', function () {
    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-ASS01',
        'user_id' => $this->client->id,
        'subject' => 'Ticket non assigné',
        'category' => TicketCategory::Messaging,
        'status' => TicketStatus::Open,
    ]);

    Passport::actingAs($this->admin);
    $response = $this->patchJson("/api/admin/support/tickets/{$ticket->id}/assign", [
        'agent_id' => $this->agent->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.assigned_agent.id', $this->agent->id)
        ->assertJsonPath('data.assigned_agent.name', 'Agent Dupont');

    expect($ticket->fresh()->assigned_to)->toBe($this->agent->id);
});

test('POST /api/admin/support/tickets/{id}/messages gère les réponses publiques et notes internes avec notifications', function () {
    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-MSG01',
        'user_id' => $this->client->id,
        'subject' => 'Question urgente',
        'category' => TicketCategory::PaymentWallet,
        'status' => TicketStatus::Open,
    ]);

    Passport::actingAs($this->admin);

    // 1. Réponse publique : notification envoyée au client
    $publicReply = $this->postJson("/api/admin/support/tickets/{$ticket->id}/messages", [
        'message' => 'Bonjour, nous avons débloqué votre compte après vérification.',
        'is_internal' => false,
    ]);

    $publicReply->assertCreated()
        ->assertJsonPath('data.sender_type', TicketMessageSenderType::Support->value)
        ->assertJsonPath('data.is_internal', false);

    Notification::assertSentTo(
        $this->client,
        SupportTicketReplyNotification::class,
        fn ($notification) => $notification->ticket->id === $ticket->id
    );

    // 2. Note interne : pas de notification envoyée au client
    Notification::fake(); // reset

    $internalNote = $this->postJson("/api/admin/support/tickets/{$ticket->id}/messages", [
        'message' => 'Contrôle KYC effectué par Admin Superieur. RAS.',
        'is_internal' => true,
    ]);

    $internalNote->assertCreated()
        ->assertJsonPath('data.sender_type', TicketMessageSenderType::InternalNote->value)
        ->assertJsonPath('data.is_internal', true);

    Notification::assertNotSentTo($this->client, SupportTicketReplyNotification::class);
});
