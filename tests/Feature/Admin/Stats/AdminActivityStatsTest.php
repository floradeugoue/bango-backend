<?php

namespace Tests\Feature\Admin\Stats;

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Housing\HousingSearch;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\Payment\PaymentTransaction;
use App\Models\Profile\Interest;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Story\Story;
use App\Models\User;
use App\Models\UserDailyActivity;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $this->adminOperator = User::factory()->create([
        'email' => 'admin.activity.'.uniqid().'@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $this->adminSessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $this->adminSessionId,
        'user_id' => $this->adminOperator->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);
});

test('GET admin/stats/activity renvoie 401 si non authentifie', function () {
    $response = $this->getJson('/admin/stats/activity');
    $response->assertStatus(401);
});

test('GET admin/stats/activity renvoie 403 si l operateur n a pas la permission stats.read', function () {
    $customRole = Role::create([
        'name' => 'Support Minimal',
        'slug' => 'support_minimal',
        'description' => 'Support sans droits stats',
        'permissions' => ['support:view'],
    ]);

    $operator = User::factory()->create([
        'email' => 'support.unauth.'.uniqid().'@bango.app',
        'role_id' => $customRole->id,
        'status' => 'active',
    ]);

    $sessId = bin2hex(random_bytes(32));
    AdminSession::create([
        'id' => $sessId,
        'user_id' => $operator->id,
        'csrf_token' => bin2hex(random_bytes(32)),
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessId)
        ->getJson('/admin/stats/activity');

    $response->assertStatus(403);
});

test('GET admin/stats/activity renvoie 200 avec structure exacte pour admin et permissions stats.read', function () {
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->adminSessionId)
        ->getJson('/admin/stats/activity?from=2026-11-01&to=2026-11-21');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'active' => ['day', 'week', 'month'],
                'days' => [
                    '*' => [
                        'date',
                        'active_users',
                        'signups',
                        'posts',
                        'stories',
                        'reels',
                        'messages',
                        'visits_booked',
                        'revenue',
                    ],
                ],
                'retention' => ['cohort_size', 'd1', 'd7', 'd30'],
                'signup_funnel' => [
                    '*' => ['step', 'count'],
                ],
            ],
        ]);

    $days = $response->json('data.days');
    expect($days)->toHaveCount(21);
    expect($days[0]['date'])->toBe('2026-11-01');
    expect($days[20]['date'])->toBe('2026-11-21');
});

test('calcul correct des utilisateurs actifs day week et month', function () {
    $refEnd = Carbon::parse('2026-11-21', 'UTC');
    Carbon::setTestNow($refEnd);

    $userRole = Role::where('slug', 'user')->firstOrFail();
    $u1 = User::factory()->create(['role_id' => $userRole->id]);
    $u2 = User::factory()->create(['role_id' => $userRole->id]);
    $u3 = User::factory()->create(['role_id' => $userRole->id]);

    // u1 actif aujourd'hui (2026-11-21)
    UserDailyActivity::create(['user_id' => $u1->id, 'date' => '2026-11-21']);
    // u2 actif il y a 3 jours (2026-11-18)
    UserDailyActivity::create(['user_id' => $u2->id, 'date' => '2026-11-18']);
    // u3 actif il y a 20 jours (2026-11-01)
    UserDailyActivity::create(['user_id' => $u3->id, 'date' => '2026-11-01']);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->adminSessionId)
        ->getJson('/admin/stats/activity?from=2026-11-01&to=2026-11-21');

    $response->assertStatus(200);
    $active = $response->json('data.active');

    expect($active['day'])->toBe(1);   // u1
    expect($active['week'])->toBe(2);  // u1, u2
    expect($active['month'])->toBe(3); // u1, u2, u3
});

test('serie chronologique complete avec jours a zero et aggregation des metriques', function () {
    $userRole = Role::where('slug', 'user')->firstOrFail();

    // Inscriptions le 2026-11-05
    $userA = User::factory()->create([
        'role_id' => $userRole->id,
        'created_at' => Carbon::parse('2026-11-05 10:00:00', 'UTC'),
    ]);
    $userB = User::factory()->create([
        'role_id' => $userRole->id,
        'created_at' => Carbon::parse('2026-11-05 14:00:00', 'UTC'),
    ]);

    // Activité
    UserDailyActivity::create(['user_id' => $userA->id, 'date' => '2026-11-05']);

    // Post
    $post = Post::create([
        'user_id' => $userA->id,
        'content' => 'Hello test post',
    ]);
    Post::where('id', $post->id)->update(['created_at' => Carbon::parse('2026-11-05 11:00:00', 'UTC')]);

    // Story
    $story = Story::create([
        'user_id' => $userA->id,
        'media_url' => 'https://example.com/story.jpg',
        'media_type' => 'image',
    ]);
    Story::where('id', $story->id)->update(['created_at' => Carbon::parse('2026-11-05 12:00:00', 'UTC')]);

    // Reel
    $reel = Reel::create([
        'user_id' => $userA->id,
        'video_url' => 'https://example.com/reel.mp4',
        'caption' => 'Reel test',
    ]);
    Reel::where('id', $reel->id)->update(['created_at' => Carbon::parse('2026-11-05 13:00:00', 'UTC')]);

    // Message
    $conv = Conversation::create([
        'type' => 'direct',
        'created_by_id' => $userA->id,
    ]);
    $msg = Message::create([
        'conversation_id' => $conv->id,
        'sender_id' => $userA->id,
        'body' => 'Message test',
        'type' => 'text',
    ]);
    Message::where('id', $msg->id)->update(['created_at' => Carbon::parse('2026-11-05 15:00:00', 'UTC')]);

    // Visite réservée
    $listing = Listing::factory()->create(['owner_id' => $userA->id]);
    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $userA->id,
        'start_at' => now()->addDays(2),
        'end_at' => now()->addDays(2)->addMinutes(30),
        'capacity' => 1,
    ]);
    $resv = VisitReservation::create([
        'reference' => 'VISIT-TEST-001',
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'owner_id' => $userA->id,
        'prospect_id' => $userA->id,
        'status' => 'confirmed',
        'fee' => 5000,
        'fee_amount' => 5000,
        'currency' => 'XAF',
    ]);
    VisitReservation::where('id', $resv->id)->update(['created_at' => Carbon::parse('2026-11-05 16:00:00', 'UTC')]);

    // Paiement encaissé (hors crédit BANGO)
    PaymentTransaction::create([
        'reference' => 'PAY-TEST-001',
        'idempotency_key' => 'idem-test-001',
        'user_id' => $userA->id,
        'service_type' => 'subscription',
        'provider' => 'monetbil',
        'channel' => 'mobile_money',
        'currency' => 'XAF',
        'amount' => 15000,
        'amount_charged' => 15000,
        'status' => 'confirmed',
        'confirmed_at' => Carbon::parse('2026-11-05 17:00:00', 'UTC'),
        'created_at' => Carbon::parse('2026-11-05 17:00:00', 'UTC'),
    ]);

    // Paiement via crédit BANGO (ne doit PAS être compté dans revenue)
    PaymentTransaction::create([
        'reference' => 'PAY-CREDIT-002',
        'idempotency_key' => 'idem-test-002',
        'user_id' => $userB->id,
        'service_type' => 'visit',
        'provider' => 'credit',
        'channel' => 'credit_wallet',
        'currency' => 'XAF',
        'amount' => 5000,
        'amount_charged' => 0,
        'credit_used' => 5000,
        'status' => 'confirmed',
        'confirmed_at' => Carbon::parse('2026-11-05 18:00:00', 'UTC'),
        'created_at' => Carbon::parse('2026-11-05 18:00:00', 'UTC'),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->adminSessionId)
        ->getJson('/admin/stats/activity?from=2026-11-04&to=2026-11-06');

    $response->assertStatus(200);
    $days = collect($response->json('data.days'))->keyBy('date');

    // 2026-11-04 : jour vide
    expect($days['2026-11-04']['active_users'])->toBe(0);
    expect($days['2026-11-04']['signups'])->toBe(0);
    expect($days['2026-11-04']['posts'])->toBe(0);
    expect($days['2026-11-04']['revenue'])->toBe(0);

    // 2026-11-05 : métriques renseignées
    expect($days['2026-11-05']['active_users'])->toBe(1);
    expect($days['2026-11-05']['signups'])->toBe(2);
    expect($days['2026-11-05']['posts'])->toBe(1);
    expect($days['2026-11-05']['stories'])->toBe(1);
    expect($days['2026-11-05']['reels'])->toBe(1);
    expect($days['2026-11-05']['messages'])->toBe(1);
    expect($days['2026-11-05']['visits_booked'])->toBe(1);
    expect($days['2026-11-05']['revenue'])->toBe(15000);
});

test('retention calcule d1 d7 et met null si comptes pas assez ages', function () {
    // Supposons qu'aujourd'hui soit le 2026-11-15
    Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));

    $userRole = Role::where('slug', 'user')->firstOrFail();

    // 2 utilisateurs créés le 2026-11-01
    $u1 = User::factory()->create([
        'role_id' => $userRole->id,
        'created_at' => Carbon::parse('2026-11-01 10:00:00', 'UTC'),
    ]);
    $u2 = User::factory()->create([
        'role_id' => $userRole->id,
        'created_at' => Carbon::parse('2026-11-01 11:00:00', 'UTC'),
    ]);

    // u1 actif à J+1 (2026-11-02) et à J+7 (2026-11-08)
    UserDailyActivity::create(['user_id' => $u1->id, 'date' => '2026-11-02']);
    UserDailyActivity::create(['user_id' => $u1->id, 'date' => '2026-11-08']);

    // u2 n'a pas été actif à J+1 ni J+7

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->adminSessionId)
        ->getJson('/admin/stats/activity?from=2026-11-01&to=2026-11-05');

    $response->assertStatus(200);
    $retention = $response->json('data.retention');

    expect($retention['cohort_size'])->toBe(2);
    expect($retention['d1'])->toBe(0.5); // 1 actif sur 2 = 50%
    expect($retention['d7'])->toBe(0.5); // 1 actif sur 2 = 50%
    // J+30 = 2026-12-01, or aujourd'hui est le 2026-11-15 -> pas encore l'âge -> null
    expect($retention['d30'])->toBeNull();
});

test('signup_funnel compte chaque etape dans l ordre', function () {
    $userRole = Role::where('slug', 'user')->firstOrFail();
    $interest = Interest::create([
        'id' => 'immo',
        'name_fr' => 'Immobilier',
        'name_en' => 'Real Estate',
    ]);

    // Utilisateur 1 : seulement compte créé
    $u1 = User::factory()->create([
        'role_id' => $userRole->id,
        'created_at' => '2026-11-05 10:00:00',
        'birth_date' => null,
        'phone_verified_at' => null,
        'avatar_url' => null,
        'terms_accepted_at' => null,
    ]);

    // Utilisateur 2 : compte + identity + phone
    $u2 = User::factory()->create([
        'role_id' => $userRole->id,
        'created_at' => '2026-11-05 10:00:00',
        'birth_date' => '1995-05-10',
        'gender' => 'man',
        'country_code' => 'CM',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'phone_verified_at' => now(),
        'avatar_url' => null,
        'terms_accepted_at' => null,
    ]);

    // Utilisateur 3 : complet jusqu a square
    $u3 = User::factory()->create([
        'role_id' => $userRole->id,
        'created_at' => '2026-11-05 10:00:00',
        'name' => 'Paul Biya',
        'handle' => 'paulb',
        'birth_date' => '1992-01-01',
        'gender' => 'man',
        'country_code' => 'CM',
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
        'phone_verified_at' => now(),
        'avatar_url' => 'https://example.com/avatar.jpg',
        'terms_accepted_at' => now(),
    ]);
    $u3->interests()->attach($interest->id);
    HousingSearch::create([
        'user_id' => $u3->id,
        'city' => 'Yaoundé',
        'budget_max' => 150000,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->adminSessionId)
        ->getJson('/admin/stats/activity?from=2026-11-01&to=2026-11-10');

    $response->assertStatus(200);
    $funnel = collect($response->json('data.signup_funnel'))->keyBy('step');

    expect($funnel['account']['count'])->toBe(3);
    expect($funnel['identity']['count'])->toBe(2); // u2, u3
    expect($funnel['phone']['count'])->toBe(2);    // u2, u3
    expect($funnel['profile']['count'])->toBe(1);  // u3
    expect($funnel['interests']['count'])->toBe(1); // u3
    expect($funnel['housing']['count'])->toBe(1);   // u3
    expect($funnel['square']['count'])->toBe(1);    // u3
});

test('RecordUserDailyActivity middleware enregistre au plus une ligne par compte et par jour', function () {
    $userRole = Role::where('slug', 'user')->firstOrFail();
    $user = User::factory()->create([
        'role_id' => $userRole->id,
        'status' => 'active',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-21 14:00:00', 'UTC'));
    Cache::flush();

    Passport::actingAs($user, ['*'], 'api');

    // Premier appel authentifié sur une route API
    $res1 = $this->getJson('/api/notifications');
    $res1->assertStatus(200);

    // Une ligne doit avoir été créée
    expect(UserDailyActivity::where('user_id', $user->id)->where('date', '2026-11-21')->count())->toBe(1);

    // Deuxième appel le même jour
    $res2 = $this->getJson('/api/notifications');
    $res2->assertStatus(200);

    // Toujours exactement 1 ligne (au plus une écriture par jour)
    expect(UserDailyActivity::where('user_id', $user->id)->where('date', '2026-11-21')->count())->toBe(1);
});

test('filtre country=CM restreint les statistiques d activite', function () {
    $userRole = Role::where('slug', 'user')->firstOrFail();

    $cmUser = User::factory()->create([
        'role_id' => $userRole->id,
        'country_code' => 'CM',
        'created_at' => '2026-11-05 10:00:00',
    ]);
    $frUser = User::factory()->create([
        'role_id' => $userRole->id,
        'country_code' => 'FR',
        'created_at' => '2026-11-05 10:00:00',
    ]);

    UserDailyActivity::create(['user_id' => $cmUser->id, 'date' => '2026-11-05']);
    UserDailyActivity::create(['user_id' => $frUser->id, 'date' => '2026-11-05']);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->adminSessionId)
        ->getJson('/admin/stats/activity?from=2026-11-01&to=2026-11-10&country=CM');

    $response->assertStatus(200);
    $days = collect($response->json('data.days'))->keyBy('date');

    // Seul le compte CM doit être comptabilisé
    expect($days['2026-11-05']['active_users'])->toBe(1);
    expect($days['2026-11-05']['signups'])->toBe(1);
    expect($response->json('data.retention.cohort_size'))->toBe(1);
});

test('GET admin/v1/stats/activity alias versionne repond a l identique', function () {
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->adminSessionId)
        ->getJson('/admin/v1/stats/activity?from=2026-11-01&to=2026-11-05');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'active',
                'days',
                'retention',
                'signup_funnel',
            ],
        ]);
});
