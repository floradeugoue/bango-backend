<?php

namespace Tests\Feature\Admin\Support;

use App\Enums\Support\HelpArticleCategory;
use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketMessageSenderType;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Support\HelpArticle;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketMessage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    Storage::fake('local');
});

function createSupportSession(string $roleSlug = 'support'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::where('email', 'jean-marc.kouassi@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => "op.support.{$roleSlug}.".uniqid().'@bango.app',
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

it('forbids unauthenticated access to support tickets', function () {
    $response = $this->getJson('/api/admin/support/tickets');
    $response->assertStatus(401);
});

it('lists support tickets with filters, pagination and overdue detection', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create(['status' => 'active']);

    // Ticket normal
    $t1 = SupportTicket::create([
        'reference' => 'TIC-2026-AAA111',
        'user_id' => $client->id,
        'subject' => 'Problème de connexion application',
        'category' => TicketCategory::AccountAuth,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
        'created_at' => now()->subHours(2),
    ]);

    // Ticket en retard (overdue > 24h)
    $t2 = SupportTicket::create([
        'reference' => 'TIC-2026-BBB222',
        'user_id' => $client->id,
        'subject' => 'Paiement non pris en compte',
        'category' => TicketCategory::PaymentWallet,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Urgent,
        'created_at' => now()->subHours(36),
        'last_reply_at' => now()->subHours(36),
    ]);

    // 1. Liste standard
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/support/tickets');

    $response->assertStatus(200)
        ->assertJsonPath('total', 2);

    // 2. Filtre overdue
    $overdueResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/support/tickets?overdue=1');

    $overdueResponse->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.reference', 'TIC-2026-BBB222');

    // 3. Recherche par référence
    $searchResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/support/tickets?search=AAA111');

    $searchResponse->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.reference', 'TIC-2026-AAA111');
});

it('returns full ticket details with chronological messages and attachments', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create(['status' => 'active']);

    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-DETAIL',
        'user_id' => $client->id,
        'subject' => 'Détail du ticket',
        'category' => TicketCategory::Other,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    SupportTicketMessage::create([
        'support_ticket_id' => $ticket->id,
        'sender_id' => $client->id,
        'sender_type' => TicketMessageSenderType::Client,
        'message' => 'Premier message du client',
        'is_internal' => false,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/support/tickets/{$ticket->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('ticket.reference', 'TIC-2026-DETAIL')
        ->assertJsonPath('ticket.messages.0.message', 'Premier message du client');
});

it('allows operator to reply to user and updates ticket status', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create(['status' => 'active']);

    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-REPLY',
        'user_id' => $client->id,
        'subject' => 'Besoin d\'assistance',
        'category' => TicketCategory::AccountAuth,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    $file = UploadedFile::fake()->create('reponse.pdf', 100, 'application/pdf');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/support/tickets/{$ticket->id}/replies", [
            'message' => 'Bonjour, voici la procédure pour réinitialiser vos paramètres.',
            'attachments' => [$file],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);

    expect(SupportTicketMessage::where('support_ticket_id', $ticket->id)->count())->toBe(1);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::InProgress);
});

it('records confidential internal notes with 5-year retention', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create(['status' => 'active']);

    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-NOTE',
        'user_id' => $client->id,
        'subject' => 'Enquête interne',
        'category' => TicketCategory::SecurityReport,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::High,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/support/tickets/{$ticket->id}/notes", [
            'message' => 'NOTE CONFIDENTIELLE : L\'adresse IP suspecte a été signalée à l\'équipe sécurité.',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true);

    $note = SupportTicketMessage::where('support_ticket_id', $ticket->id)->firstOrFail();
    expect($note->is_internal)->toBeTrue()
        ->and($note->sender_type)->toBe(TicketMessageSenderType::InternalNote)
        ->and($note->retained_until)->not->toBeNull()
        ->and($note->retained_until->isFuture())->toBeTrue();
});

it('assigns single and bulk tickets to an active agent', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create(['status' => 'active']);

    $t1 = SupportTicket::create([
        'reference' => 'TIC-2026-ASG1',
        'user_id' => $client->id,
        'subject' => 'Ticket 1',
        'category' => TicketCategory::Other,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    $t2 = SupportTicket::create([
        'reference' => 'TIC-2026-ASG2',
        'user_id' => $client->id,
        'subject' => 'Ticket 2',
        'category' => TicketCategory::Other,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/support/tickets/assign', [
            'ticket_ids' => [$t1->id, $t2->id],
            'agent_id' => $operator->id,
            'reason' => 'Assignation pour instruction directe niveau 2',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.assigned_count', 2);

    expect($t1->fresh()->assigned_to)->toBe($operator->id)
        ->and($t2->fresh()->assigned_to)->toBe($operator->id);
});

it('resolves a ticket and allows reopening strictly within 7 days', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create(['status' => 'active']);

    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-RESOLVE',
        'user_id' => $client->id,
        'subject' => 'Problème résolu',
        'category' => TicketCategory::Other,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    // 1. Résolution
    $resolveResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/support/tickets/{$ticket->id}/resolve", [
            'category' => 'bug_fixed',
            'reason' => 'Patch déployé en production, problème corrigé.',
        ]);

    $resolveResponse->assertStatus(200)
        ->assertJsonPath('data.status', TicketStatus::Resolved->value)
        ->assertJsonPath('data.resolution_category', 'bug_fixed');

    expect($ticket->fresh()->resolved_at)->not->toBeNull();

    // 2. Réouverture dans la fenêtre de 7 jours (ici immédiate)
    $reopenResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/support/tickets/{$ticket->id}/reopen", [
            'reason' => 'Le client signale que le problème persiste sur mobile.',
        ]);

    $reopenResponse->assertStatus(200)
        ->assertJsonPath('data.status', TicketStatus::InProgress->value);

    // 3. Forcer resolved_at à il y a 8 jours et tenter la réouverture (doit échouer)
    $ticket->update([
        'status' => TicketStatus::Resolved,
        'resolved_at' => Carbon::now()->subDays(8),
    ]);

    $expiredResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/support/tickets/{$ticket->id}/reopen", [
            'reason' => 'Tentative de réouverture hors délai',
        ]);

    $expiredResponse->assertStatus(500); // DomainException catchée
});

it('escalates ticket for opinion to another team and answers it', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create(['status' => 'active']);

    $ticket = SupportTicket::create([
        'reference' => 'TIC-2026-ESCALATE',
        'user_id' => $client->id,
        'subject' => 'Cas complexe juridique',
        'category' => TicketCategory::SecurityReport,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::High,
    ]);

    // 1. Escalade pour avis
    $escalateResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/support/tickets/{$ticket->id}/escalate", [
            'target_team' => 'legal',
            'reason' => 'Demande d\'interprétation des CGU sur la résiliation anticipée.',
        ]);

    $escalateResponse->assertStatus(201)
        ->assertJsonPath('data.target_team', 'legal')
        ->assertJsonPath('data.status', 'pending');

    $escalationId = $escalateResponse->json('data.id');

    // 2. Réponse de l'équipe juridique
    $answerResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/support/escalation/answer', [
            'escalation_id' => $escalationId,
            'response' => 'Avis juridique : l\'article 14 s\'applique, vous pouvez accorder le remboursement.',
        ]);

    $answerResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'answered')
        ->assertJsonPath('data.response', 'Avis juridique : l\'article 14 s\'applique, vous pouvez accorder le remboursement.');
});

it('creates manual ticket with telephone or external channel', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create(['status' => 'active']);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/support/tickets', [
            'user_id' => $client->id,
            'subject' => 'Appel entrant : Réclamation loyer',
            'category' => TicketCategory::PaymentWallet->value,
            'priority' => 'high',
            'channel' => 'phone',
            'message' => 'L\'utilisateur a appelé le standard téléphonique ce matin suite à un problème de prélèvement.',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.channel', 'phone')
        ->assertJsonPath('data.priority', 'high');
});

it('manages canned response templates CRUD', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');

    // 1. Création
    $createResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/support/templates', [
            'title' => 'Formule de politesse standard',
            'code' => 'GREET_STD',
            'category' => 'greetings',
            'content' => 'Bonjour, merci d\'avoir contacté le support BANGO. Comment pouvons-nous vous aider ?',
            'locale' => 'fr',
        ]);

    $createResponse->assertStatus(201)
        ->assertJsonPath('data.code', 'GREET_STD');

    $templateId = $createResponse->json('data.id');

    // 2. Mise à jour
    $updateResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->putJson("/api/admin/support/templates/{$templateId}", [
            'title' => 'Formule de politesse mise à jour',
            'category' => 'greetings',
            'content' => 'Bonjour et bienvenue sur le support BANGO.',
        ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.title', 'Formule de politesse mise à jour');

    // 3. Liste
    $listResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/support/templates');

    $listResponse->assertStatus(200)
        ->assertJsonPath('success', true);
});

it('manages help center articles and updates content', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');

    $article = HelpArticle::create([
        'slug' => 'comment-creer-un-compte',
        'category' => HelpArticleCategory::AccountAuth,
        'title' => 'Comment créer un compte ?',
        'content_markdown' => 'Pour créer un compte, téléchargez l\'application BANGO...',
        'locale' => 'fr',
        'is_published' => true,
    ]);

    // 1. Consultation
    $showResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/support/help/{$article->id}");

    $showResponse->assertStatus(200)
        ->assertJsonPath('article.slug', 'comment-creer-un-compte');

    // 2. Mise à jour
    $updateResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->putJson("/api/admin/support/help/{$article->id}", [
            'title' => 'Comment créer un compte BANGO facilement ?',
            'content_markdown' => 'Nouveau guide 2026 : Téléchargez l\'app et suivez les étapes.',
        ]);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('article.title', 'Comment créer un compte BANGO facilement ?');
});

it('exports tickets to CSV with formula injection protection', function () {
    [$operator, $sessionId, $csrfToken] = createSupportSession('support');
    $client = User::factory()->create([
        'name' => '=HYPERLINK("http://evil.com","Click")',
        'status' => 'active',
    ]);

    SupportTicket::create([
        'reference' => 'TIC-2026-CSV1',
        'user_id' => $client->id,
        'subject' => 'Test export CSV',
        'category' => TicketCategory::Other,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/support/exports');

    $response->assertStatus(200);
    $content = $response->streamedContent();

    expect($content)->toContain('Reference,Demandeur_Nom')
        ->and($content)->toContain("'=HYPERLINK"); // Neutralisation anti-injection
});
