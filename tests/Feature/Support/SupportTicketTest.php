<?php

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketMessageSenderType;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Auth\Role;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketAttachment;
use App\Models\Support\SupportTicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Storage::fake('local');
    Notification::fake();

    $this->client = User::factory()->create(['name' => 'Client Test', 'email' => 'client@test.com']);
    $this->otherClient = User::factory()->create(['name' => 'Autre Client', 'email' => 'autre@test.com']);

    $supportRole = Role::firstOrCreate(['slug' => 'support'], ['name' => 'Support']);
    $this->agent = User::factory()->create(['name' => 'Agent Support', 'role_id' => $supportRole->id]);
});

// =========================================================================
// 1. CRÉATION D'UN TICKET (POST /api/support/tickets)
// =========================================================================

test('POST /api/support/tickets permet à un utilisateur connecté de créer un ticket avec pièces jointes', function () {
    Passport::actingAs($this->client);

    $file = UploadedFile::fake()->create('capture_ecran.png', 150, 'image/png');

    $response = $this->postJson('/api/support/tickets', [
        'subject' => 'Problème de déblocage de caution',
        'category' => TicketCategory::PaymentWallet->value,
        'message' => 'Bonjour, mon virement Wave de caution n\'apparaît pas dans mon historique.',
        'priority' => TicketPriority::High->value,
        'attachments' => [$file],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.subject', 'Problème de déblocage de caution')
        ->assertJsonPath('data.category.value', TicketCategory::PaymentWallet->value)
        ->assertJsonPath('data.priority.value', TicketPriority::High->value)
        ->assertJsonPath('data.status.value', TicketStatus::Open->value);

    $reference = $response->json('data.reference');
    expect($reference)->toStartWith('TIC-');

    $this->assertDatabaseHas('support_tickets', [
        'reference' => $reference,
        'user_id' => $this->client->id,
        'subject' => 'Problème de déblocage de caution',
        'status' => TicketStatus::Open->value,
    ]);

    $ticket = SupportTicket::where('reference', $reference)->first();
    expect($ticket->messages)->toHaveCount(1);

    $initialMessage = $ticket->messages->first();
    expect($initialMessage->message)->toBe('Bonjour, mon virement Wave de caution n\'apparaît pas dans mon historique.')
        ->and($initialMessage->sender_type)->toBe(TicketMessageSenderType::Client)
        ->and($initialMessage->attachments)->toHaveCount(1);
});

test('POST /api/support/tickets requiert une authentification', function () {
    $this->postJson('/api/support/tickets', [
        'subject' => 'Sujet test',
        'category' => TicketCategory::Other->value,
        'message' => 'Message sans authentification',
    ])->assertUnauthorized();
});

test('POST /api/support/tickets valide strictement les champs et pièces jointes', function () {
    Passport::actingAs($this->client);

    // Rejet : sujet absent, catégorie invalide, message trop court
    $this->postJson('/api/support/tickets', [
        'subject' => '',
        'category' => 'categorie_inconnue',
        'message' => 'abc',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['subject', 'category', 'message']);

    // Rejet : type de fichier non autorisé (.exe / .sh)
    $badFile = UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload');
    $this->postJson('/api/support/tickets', [
        'subject' => 'Ticket avec script',
        'category' => TicketCategory::Other->value,
        'message' => 'Contenu valide avec pièce jointe interdite',
        'attachments' => [$badFile],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['attachments.0']);
});

// =========================================================================
// 2. LISTE ET DÉTAIL DES TICKETS
// =========================================================================

test('GET /api/support/tickets/mine liste uniquement les tickets du client connecté', function () {
    Passport::actingAs($this->client);

    $ticket1 = SupportTicket::create([
        'reference' => 'TIC-2026-AAA111',
        'user_id' => $this->client->id,
        'subject' => 'Ticket 1 Client',
        'category' => TicketCategory::AccountAuth,
        'status' => TicketStatus::Open,
        'last_reply_at' => now(),
    ]);

    // Ticket d'un autre client
    SupportTicket::create([
        'reference' => 'TIC-2026-BBB222',
        'user_id' => $this->otherClient->id,
        'subject' => 'Ticket Autre Client',
        'category' => TicketCategory::Messaging,
        'status' => TicketStatus::Open,
        'last_reply_at' => now(),
    ]);

    $response = $this->getJson('/api/support/tickets/mine');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'TIC-2026-AAA111');
});

test('GET /api/support/tickets/{id} masque strictement les notes internes au client', function () {
    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-SEC001',
        'user_id' => $this->client->id,
        'subject' => 'Contestation de vérification KYC',
        'category' => TicketCategory::AccountAuth,
        'status' => TicketStatus::InProgress,
        'last_reply_at' => now(),
    ]);

    // Message public du client
    SupportTicketMessage::create([
        'support_ticket_id' => $ticket->id,
        'sender_id' => $this->client->id,
        'sender_type' => TicketMessageSenderType::Client,
        'message' => 'J\'ai fourni ma pièce d\'identité.',
        'is_internal' => false,
    ]);

    // Note interne rédigée par un agent support
    SupportTicketMessage::create([
        'support_ticket_id' => $ticket->id,
        'sender_id' => $this->agent->id,
        'sender_type' => TicketMessageSenderType::InternalNote,
        'message' => 'NOTE CONFIDENTIELLE : Usager suspect, vérifier le CNI auprès des autorités.',
        'is_internal' => true,
    ]);

    // Consultation par le client propriétaire
    Passport::actingAs($this->client);
    $clientResponse = $this->getJson("/api/support/tickets/{$ticket->id}");

    $clientResponse->assertOk()
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonMissing(['message' => 'NOTE CONFIDENTIELLE : Usager suspect, vérifier le CNI auprès des autorités.']);

    // Consultation par l'agent support : la note interne doit être visible
    Passport::actingAs($this->agent);
    $agentResponse = $this->getJson("/api/support/tickets/{$ticket->id}");

    $agentResponse->assertOk()
        ->assertJsonCount(2, 'data.messages');
});

test('GET /api/support/tickets/{id} interdit l\'accès au ticket d\'un tiers', function () {
    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-OTHER1',
        'user_id' => $this->otherClient->id,
        'subject' => 'Ticket privé autre client',
        'category' => TicketCategory::AccountAuth,
        'status' => TicketStatus::Open,
    ]);

    Passport::actingAs($this->client);
    $this->getJson("/api/support/tickets/{$ticket->id}")->assertForbidden();
});

// =========================================================================
// 3. RÉPONSE DU CLIENT & TRANSITIONS D'ÉTAT
// =========================================================================

test('POST /api/support/tickets/{id}/messages permet au client de répondre et réactive un ticket en attente', function () {
    Passport::actingAs($this->client);

    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-WAIT01',
        'user_id' => $this->client->id,
        'subject' => 'Besoin d\'informations complémentaires',
        'category' => TicketCategory::RealEstateVisits,
        'status' => TicketStatus::WaitingForUser,
        'last_reply_at' => now()->subDay(),
    ]);

    $response = $this->postJson("/api/support/tickets/{$ticket->id}/messages", [
        'message' => 'Voici les informations que vous avez demandées : date du 20 octobre.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.message', 'Voici les informations que vous avez demandées : date du 20 octobre.')
        ->assertJsonPath('data.sender_type', TicketMessageSenderType::Client->value);

    // Le statut du ticket doit être passé automatiquement à in_progress
    expect($ticket->fresh()->status)->toBe(TicketStatus::InProgress);
});

test('POST /api/support/tickets/{id}/messages refuse la réponse d\'un client sur un ticket fermé', function () {
    Passport::actingAs($this->client);

    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-CLOSED',
        'user_id' => $this->client->id,
        'subject' => 'Ticket résolu et archivé',
        'category' => TicketCategory::Other,
        'status' => TicketStatus::Closed,
    ]);

    $response = $this->postJson("/api/support/tickets/{$ticket->id}/messages", [
        'message' => 'Je souhaite ajouter un élément...',
    ]);

    $response->assertForbidden();
});

// =========================================================================
// 4. TÉLÉCHARGEMENT SÉCURISÉ DES PIÈCES JOINTES
// =========================================================================

test('GET /api/support/tickets/{ticketId}/attachments/{attachmentId} télécharge le fichier après contrôle d\'accès', function () {
    Passport::actingAs($this->client);

    $file = UploadedFile::fake()->create('justificatif.pdf', 200, 'application/pdf');

    $createResponse = $this->postJson('/api/support/tickets', [
        'subject' => 'Test PJ',
        'category' => TicketCategory::Other->value,
        'message' => 'Voici mon justificatif en pièce jointe.',
        'attachments' => [$file],
    ]);

    $ticketId = $createResponse->json('data.id');
    $attachment = SupportTicketAttachment::where('support_ticket_id', $ticketId)->first();

    // 1. Le propriétaire peut télécharger
    $downloadResponse = $this->get("/api/support/tickets/{$ticketId}/attachments/{$attachment->id}");
    $downloadResponse->assertOk();

    // 2. Un autre client est rejeté avec 403 Forbidden
    Passport::actingAs($this->otherClient);
    $this->getJson("/api/support/tickets/{$ticketId}/attachments/{$attachment->id}")
        ->assertForbidden();

    // 3. Un agent support peut télécharger
    Passport::actingAs($this->agent);
    $this->get("/api/support/tickets/{$ticketId}/attachments/{$attachment->id}")
        ->assertOk();
});
