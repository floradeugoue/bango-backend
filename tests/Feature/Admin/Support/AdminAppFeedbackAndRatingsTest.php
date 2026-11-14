<?php

namespace Tests\Feature\Admin\Support;

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Support\AppFeedback;
use App\Models\Support\SupportTicket;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createSupportAdminSession(string $roleSlug = 'support'): array
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

it('allows admin to list and filter app feedbacks with mood counters', function () {
    [$operator, $sessionId, $csrfToken] = createSupportAdminSession('support');

    $userA = User::factory()->create(['name' => 'Alice Martin', 'handle' => 'alice_m']);
    $userB = User::factory()->create(['name' => 'Bob Dupont', 'handle' => 'bob_d']);

    AppFeedback::create([
        'user_id' => $userA->id,
        'mood' => 'great',
        'comment' => 'Application très fluide et intuitive !',
        'platform' => 'android',
        'app_version' => '1.4.0',
    ]);

    AppFeedback::create([
        'user_id' => $userB->id,
        'mood' => 'fine',
        'comment' => 'Bien dans l ensemble mais parfois lent',
        'platform' => 'ios',
        'app_version' => '1.4.0',
    ]);

    AppFeedback::create([
        'user_id' => $userA->id,
        'mood' => 'poor',
        'comment' => 'Impossible de charger les images',
        'platform' => 'android',
        'app_version' => '1.3.9',
    ]);

    // 1. Lister tous les feedbacks
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/app-feedback');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'items',
            'counts' => ['all', 'great', 'fine', 'poor'],
            'meta',
        ]);

    expect($response->json('counts.all'))->toBe(3);
    expect($response->json('counts.great'))->toBe(1);
    expect($response->json('counts.fine'))->toBe(1);
    expect($response->json('counts.poor'))->toBe(1);

    // 2. Filtrer par humeur (mood = great)
    $greatResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/app-feedback?mood=great');

    $greatResponse->assertOk();
    expect(count($greatResponse->json('items')))->toBe(1);
    expect($greatResponse->json('items.0.mood'))->toBe('great');

    // 3. Filtrer par plateforme (platform = ios)
    $iosResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/app-feedback?platform=ios');

    $iosResponse->assertOk();
    expect(count($iosResponse->json('items')))->toBe(1);
    expect($iosResponse->json('items.0.platform'))->toBe('ios');

    // 4. Filtrer par recherche (q = images)
    $searchResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/app-feedback?q=images');

    $searchResponse->assertOk();
    expect(count($searchResponse->json('items')))->toBe(1);
    expect($searchResponse->json('items.0.mood'))->toBe('poor');
});

it('exposes rating details in admin tickets and calculates advisor ratings', function () {
    [$operator, $sessionId, $csrfToken] = createSupportAdminSession('support');

    $advisorA = User::factory()->create([
        'name' => 'Conseiller Alpha',
        'email' => 'advisor.a@bango.app',
        'role_id' => Role::where('slug', 'support')->first()->id,
        'status' => 'active',
    ]);

    $advisorB = User::factory()->create([
        'name' => 'Conseiller Beta',
        'email' => 'advisor.b@bango.app',
        'role_id' => Role::where('slug', 'support')->first()->id,
        'status' => 'active',
    ]);

    $client = User::factory()->create(['name' => 'Client Satisfait']);

    // Ticket 1: noté 5 étoiles
    $ticket1 = SupportTicket::create([
        'reference' => 'TICK-'.bin2hex(random_bytes(4)),
        'user_id' => $client->id,
        'assigned_to' => $advisorA->id,
        'subject' => 'Problème de connexion résolu',
        'category' => TicketCategory::AccountAuth,
        'status' => TicketStatus::Resolved,
        'priority' => TicketPriority::Normal,
        'rating' => 5,
        'rating_comment' => 'Service rapide et efficace !',
        'rated_at' => now(),
    ]);

    // Ticket 2: noté 3 étoiles
    $ticket2 = SupportTicket::create([
        'reference' => 'TICK-'.bin2hex(random_bytes(4)),
        'user_id' => $client->id,
        'assigned_to' => $advisorA->id,
        'subject' => 'Question abonnement',
        'category' => TicketCategory::SubscriptionBadge,
        'status' => TicketStatus::Resolved,
        'priority' => TicketPriority::Normal,
        'rating' => 3,
        'rating_comment' => 'Correct mais un peu lent',
        'rated_at' => now(),
    ]);

    // Ticket 3: noté 4 étoiles pour advisorB
    $ticket3 = SupportTicket::create([
        'reference' => 'TICK-'.bin2hex(random_bytes(4)),
        'user_id' => $client->id,
        'assigned_to' => $advisorB->id,
        'subject' => 'Dossier vérification',
        'category' => TicketCategory::ProPages,
        'status' => TicketStatus::Resolved,
        'priority' => TicketPriority::High,
        'rating' => 4,
        'rating_comment' => 'Très professionnel',
        'rated_at' => now(),
    ]);

    // 1. Consulter les détails d'un ticket individuel
    $showResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/support/tickets/{$ticket1->id}");

    $showResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('ticket.id', $ticket1->id)
        ->assertJsonPath('ticket.rating', 5)
        ->assertJsonPath('ticket.rating_comment', 'Service rapide et efficace !');

    // 2. Consulter la liste des assignees avec note moyenne rattachée
    $assigneesResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/support/assignees');

    $assigneesResponse->assertOk();
    $assigneesData = collect($assigneesResponse->json('data'));
    $advisorAData = $assigneesData->firstWhere('id', $advisorA->id);

    expect($advisorAData)->not->toBeNull();
    // Moyenne de 5 et 3 = 4.0
    expect((float) $advisorAData['average_rating'])->toBe(4.0);
    expect((int) $advisorAData['ratings_count'])->toBe(2);

    // 3. Consulter les statistiques et moyennes par conseiller (/support/advisors/ratings)
    $ratingsResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/support/advisors/ratings');

    $ratingsResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'global_average',
                'total_ratings',
                'stars_distribution' => ['1', '2', '3', '4', '5'],
                'advisors',
            ],
        ]);

    // Total 3 ratings: (5 + 3 + 4) / 3 = 4.0
    expect($ratingsResponse->json('data.total_ratings'))->toBe(3);
    expect((float) $ratingsResponse->json('data.global_average'))->toBe(4.0);
    expect($ratingsResponse->json('data.stars_distribution.5'))->toBe(1);
    expect($ratingsResponse->json('data.stars_distribution.3'))->toBe(1);
    expect($ratingsResponse->json('data.stars_distribution.4'))->toBe(1);

    $advisorsList = collect($ratingsResponse->json('data.advisors'));
    $statAdvisorA = $advisorsList->firstWhere('advisor_id', $advisorA->id);
    expect($statAdvisorA)->not->toBeNull();
    expect((float) $statAdvisorA['average_rating'])->toBe(4.0);
    expect((int) $statAdvisorA['ratings_count'])->toBe(2);
});
