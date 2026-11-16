<?php

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketMessageSenderType;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketMessage;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

test('getAdvisorRatings attribue la note a assigned_to, sinon a l operateur ayant resolu ou repondu en dernier', function () {
    $supportRole = Role::where('slug', 'support')->firstOrFail();

    $operator1 = User::factory()->create([
        'name' => 'fatou',
        'display_name' => 'Fatou Support',
        'email' => 'fatou.'.uniqid().'@bango.app',
        'role_id' => $supportRole->id,
        'status' => 'active',
    ]);

    $operator2 = User::factory()->create([
        'name' => 'samuel',
        'display_name' => 'Samuel Support',
        'email' => 'samuel.'.uniqid().'@bango.app',
        'role_id' => $supportRole->id,
        'status' => 'active',
    ]);

    $client = User::factory()->create(['name' => 'client_test']);

    // Session admin pour Fatou
    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator1->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    // Cas du constat : 4 tickets notés, SANS assigned_to renseigné (assigned_to = null)
    // Ticket 1 : Fatou a répondu en dernier (note 5)
    $t1 = SupportTicket::create([
        'reference' => 'TCK-202611-001',
        'user_id' => $client->id,
        'assigned_to' => null,
        'subject' => 'Problème de connexion',
        'category' => TicketCategory::AccountAuth,
        'channel' => 'chat',
        'status' => TicketStatus::Closed,
        'priority' => TicketPriority::Normal,
        'rating' => 5,
        'rated_at' => now()->subDays(2),
    ]);
    SupportTicketMessage::create([
        'support_ticket_id' => $t1->id,
        'sender_id' => $client->id,
        'sender_type' => TicketMessageSenderType::Client,
        'message' => 'Aidez-moi svp',
        'is_internal' => false,
        'created_at' => now()->subDays(3),
    ]);
    SupportTicketMessage::create([
        'support_ticket_id' => $t1->id,
        'sender_id' => $operator1->id,
        'sender_type' => TicketMessageSenderType::Support,
        'message' => 'Voici la solution',
        'is_internal' => false,
        'created_at' => now()->subDays(2),
    ]);

    // Ticket 2 : Fatou a résolu le ticket sans assignation (note 4)
    $t2 = SupportTicket::create([
        'reference' => 'TCK-202611-002',
        'user_id' => $client->id,
        'assigned_to' => null,
        'resolved_by_id' => $operator1->id,
        'subject' => 'Question facturation',
        'category' => TicketCategory::PaymentWallet,
        'channel' => 'email',
        'status' => TicketStatus::Resolved,
        'priority' => TicketPriority::Normal,
        'rating' => 4,
        'rated_at' => now()->subDay(),
    ]);

    // Ticket 3 : Samuel a répondu en dernier sans assignation (note 2)
    $t3 = SupportTicket::create([
        'reference' => 'TCK-202611-003',
        'user_id' => $client->id,
        'assigned_to' => null,
        'subject' => 'Délai trop long',
        'category' => TicketCategory::Other,
        'channel' => 'app',
        'status' => TicketStatus::Closed,
        'priority' => TicketPriority::High,
        'rating' => 2,
        'rated_at' => now()->subHours(12),
    ]);
    SupportTicketMessage::create([
        'support_ticket_id' => $t3->id,
        'sender_id' => $operator2->id,
        'sender_type' => TicketMessageSenderType::Support,
        'message' => 'Nous traitons votre demande',
        'is_internal' => false,
        'created_at' => now()->subHours(13),
    ]);

    // Ticket 4 : Directement assigné à Fatou (note 5)
    $t4 = SupportTicket::create([
        'reference' => 'TCK-202611-004',
        'user_id' => $client->id,
        'assigned_to' => $operator1->id,
        'subject' => 'Visite confirmée',
        'category' => TicketCategory::RealEstateVisits,
        'channel' => 'chat',
        'status' => TicketStatus::Closed,
        'priority' => TicketPriority::Normal,
        'rating' => 5,
        'rated_at' => now()->subHours(5),
    ]);

    // Appel de la route GET /api/admin/support/advisors/ratings
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/support/advisors/ratings');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $data = $response->json('data');

    // Vérification globale
    expect($data['total_ratings'])->toBe(4)
        ->and($data['positive_count'])->toBe(3) // 5, 4, 5
        ->and((float) $data['positive_rate'])->toBe(75.0) // 3 sur 4 = 75%
        ->and((float) $data['global_average'])->toBe(4.0); // (5+4+2+5)/4 = 4.0

    // Vérification des conseillers : advisors NE DOIT PLUS ÊTRE VIDE !
    $advisors = collect($data['advisors']);
    expect($advisors)->toHaveCount(2);

    // Fatou a reçu 3 tickets (t1 via réponse, t2 via résolution, t4 via assigned_to)
    $fatouStats = $advisors->firstWhere('advisor_id', $operator1->id);
    expect($fatouStats)->not->toBeNull()
        ->and($fatouStats['name'])->toBe('Fatou Support')
        ->and($fatouStats['ratings_count'])->toBe(3)
        ->and($fatouStats['positive_count'])->toBe(3) // toutes >= 4 (5, 4, 5)
        ->and((float) $fatouStats['positive_rate'])->toBe(100.0)
        ->and((float) $fatouStats['average_rating'])->toBe(4.67);

    // Samuel a reçu 1 ticket (t3 via réponse)
    $samuelStats = $advisors->firstWhere('advisor_id', $operator2->id);
    expect($samuelStats)->not->toBeNull()
        ->and($samuelStats['name'])->toBe('Samuel Support')
        ->and($samuelStats['ratings_count'])->toBe(1)
        ->and($samuelStats['positive_count'])->toBe(0) // note 2 < 4
        ->and((float) $samuelStats['positive_rate'])->toBe(0.0)
        ->and((float) $samuelStats['average_rating'])->toBe(2.0);

    // Test du filtre advisor_id sur advisorRatings
    $filterRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/support/advisors/ratings?advisor_id={$operator1->id}");

    $filterRes->assertOk();
    $filterData = $filterRes->json('data');
    expect($filterData['total_ratings'])->toBe(3)
        ->and($filterData['positive_count'])->toBe(3)
        ->and(collect($filterData['advisors']))->toHaveCount(1)
        ->and($filterData['advisors'][0]['advisor_id'])->toBe($operator1->id);

    // Test du filtre advisor_id=me sur advisorRatings
    $meRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/support/advisors/ratings?advisor_id=me');

    $meRes->assertOk();
    $meData = $meRes->json('data');
    expect($meData['total_ratings'])->toBe(3)
        ->and($meData['advisors'][0]['advisor_id'])->toBe($operator1->id);

    // Test du filtre advisor_id sur la liste des tickets (GET /api/admin/support/tickets?advisor_id=...)
    $ticketsRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/support/tickets?advisor_id={$operator1->id}");

    $ticketsRes->assertOk();
    $items = $ticketsRes->json('items') ?? $ticketsRes->json('data.items') ?? $ticketsRes->json('data');
    $ticketReferences = collect($items)->pluck('reference')->all();
    expect($ticketReferences)->toContain('TCK-202611-001') // Fatou a répondu
        ->and($ticketReferences)->toContain('TCK-202611-002') // Fatou a résolu
        ->and($ticketReferences)->toContain('TCK-202611-004') // Fatou assignée
        ->and($ticketReferences)->not->toContain('TCK-202611-003'); // Samuel a répondu
});
