<?php

namespace Tests\Feature\Admin\Kyc;

use App\Enums\Kyc\KycStatus;
use App\Enums\Support\AppealStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycSubmission;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\Support\ModerationAppeal;
use App\Models\User;
use App\Services\Admin\AdminCounterService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);
});

function createEnhancementSession(string $roleSlug = 'verification'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'name' => 'Solange Test',
        'email' => 'solange.enh.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

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

// 1. Menu : kyc_pending avec whereHas('user') exclut les comptes supprimés
it('counts only pending submissions of existing active users in kyc_pending counter', function () {
    [$operator, $sessionId] = createEnhancementSession('verification');

    $activeUser = User::factory()->create(['name' => 'Active User']);
    $deletedUser = User::factory()->create(['name' => 'Deleted User']);

    KycSubmission::create([
        'user_id' => $activeUser->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
    ]);

    KycSubmission::create([
        'user_id' => $deletedUser->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
    ]);

    // Delete user
    $deletedUser->delete();

    $counterService = app(AdminCounterService::class);
    $counters = $counterService->getCounters($operator);

    // Only active user is counted
    expect($counters['counters']['kyc_pending'])->toBe(1);
});

// 2. admin/kyc/requests : calculs dynamiques des facets, docs requis et position
it('calculates dynamic facets, docs.required and headline in admin/kyc/requests', function () {
    [$operator, $sessionId] = createEnhancementSession('verification');

    $u1 = User::factory()->create(['name' => 'User Dakar', 'city' => 'Dakar']);
    $u2 = User::factory()->create(['name' => 'User Thies', 'city' => 'Thies']);

    $s1 = KycSubmission::create([
        'user_id' => $u1->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'id_number' => 'CNI-1',
        'metadata' => ['kind' => 'renewal'],
    ]);

    $s2 = KycSubmission::create([
        'user_id' => $u2->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'agent',
        'metadata' => ['kind' => 'new'],
    ]);

    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/requests');

    $res->assertStatus(200);

    // Dynamic cities
    $cities = collect($res->json('facets.cities'));
    expect($cities->pluck('value')->all())->toContain('Dakar', 'Thies');

    // Dynamic badges
    $badges = collect($res->json('facets.badges'));
    expect($badges->firstWhere('value', 'bailleur')['count'])->toBe(1);
    expect($badges->firstWhere('value', 'agent')['count'])->toBe(1);

    // Dynamic kinds
    $kinds = collect($res->json('facets.kinds'));
    expect($kinds->firstWhere('value', 'renewal')['count'])->toBe(1);
    expect($kinds->firstWhere('value', 'new')['count'])->toBe(1);

    // Docs required in items
    $items = collect($res->json('items'));
    $item1 = $items->firstWhere('id', (string) $s1->id);
    expect($item1['docs']['required'])->toBe(4);
    expect($item1['account']['display_name'])->toBe($u1->name);
    expect($item1['account']['city'])->toBe('Dakar');
});

// 3. Dossier : account.display_name, avatar_url, city, et position dans la file
it('returns account details and queue position { index, total, prev, next } in show', function () {
    [$operator, $sessionId] = createEnhancementSession('verification');

    $user1 = User::factory()->create([
        'name' => 'Premier User',
        'display_name' => 'Premier Affiché',
        'city' => 'Saint-Louis',
        'avatar_url' => 'https://example.com/avatar1.jpg',
    ]);
    $user2 = User::factory()->create([
        'name' => 'Deuxieme User',
        'display_name' => 'Deuxieme Affiché',
        'city' => 'Dakar',
        'avatar_url' => 'https://example.com/avatar2.jpg',
    ]);

    $sub1 = KycSubmission::create([
        'user_id' => $user1->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'created_at' => now()->subHours(2),
    ]);

    $sub2 = KycSubmission::create([
        'user_id' => $user2->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'agent',
        'created_at' => now()->subHour(),
    ]);

    // Inspect sub1
    $res1 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/kyc/requests/{$sub1->id}");

    $res1->assertStatus(200);
    $data1 = $res1->json('data');

    expect($data1['account']['display_name'])->toBe('Premier Affiché');
    expect($data1['account']['avatar_url'])->toBe('https://example.com/avatar1.jpg');
    expect($data1['account']['city'])->toBe('Saint-Louis');
    expect($data1['position']['index'])->toBe(1);
    expect($data1['position']['total'])->toBe(2);
    expect($data1['position']['prev'])->toBeNull();
    expect($data1['position']['next'])->toBe($sub2->reference);

    // Inspect sub2
    $res2 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/kyc/requests/{$sub2->id}");

    $res2->assertStatus(200);
    $data2 = $res2->json('data');
    expect($data2['position']['index'])->toBe(2);
    expect($data2['position']['total'])->toBe(2);
    expect($data2['position']['prev'])->toBe($sub1->reference);
    expect($data2['position']['next'])->toBeNull();
});

// 4. admin/kyc/badges : display_name, avatar_url, city, abs diffInDays, filtres et tris
it('lists only badged users, supports filters city, cadence, badge, and sort in admin/kyc/badges', function () {
    [$operator, $sessionId] = createEnhancementSession('verification');

    // User A: Verified but NO badge -> MUST NOT be in list
    $uNoBadge = User::factory()->create([
        'name' => 'Verified Sans Badge',
        'badge' => 'none',
        'is_verified' => true,
    ]);

    // User B: Landlord badge in Dakar, monthly cadence, expiring in 20 days
    $uBailleur = User::factory()->create([
        'name' => 'Bailleur Alpha',
        'display_name' => 'Alpha Bailleur Affiché',
        'badge' => 'landlord',
        'city' => 'Dakar',
        'avatar_url' => 'https://example.com/alpha.jpg',
    ]);
    $product = SubscriptionProduct::first();
    $price = SubscriptionPrice::first();

    Subscription::create([
        'user_id' => $uBailleur->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'state' => 'active',
        'amount' => 5000,
        'currency' => 'XOF',
        'current_period_start' => now()->subDays(10),
        'current_period_end' => now()->addDays(20),
    ]);

    // User C: Agent badge in Thies, yearly cadence, expiring in 3 days (expiring soon)
    $uAgent = User::factory()->create([
        'name' => 'Agent Beta',
        'display_name' => 'Beta Agent Affiché',
        'badge' => 'agent',
        'city' => 'Thies',
        'avatar_url' => 'https://example.com/beta.jpg',
    ]);
    Subscription::create([
        'user_id' => $uAgent->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'badge_type' => 'agent',
        'cadence' => 'yearly',
        'state' => 'active',
        'amount' => 50000,
        'currency' => 'XOF',
        'current_period_start' => now()->subDays(360),
        'current_period_end' => now()->addDays(3),
    ]);

    // Base request
    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/badges');

    $res->assertStatus(200);
    $items = collect($res->json('data'));

    // Verified account without badge is excluded
    expect($items->pluck('user_id'))->not->toContain($uNoBadge->id);

    // Badged users are included
    expect($items->pluck('user_id'))->toContain($uBailleur->id, $uAgent->id);

    // Fields display_name, avatar_url, city
    $itemB = $items->firstWhere('user_id', $uBailleur->id);
    expect($itemB['display_name'])->toBe('Alpha Bailleur Affiché');
    expect($itemB['avatar_url'])->toBe('https://example.com/alpha.jpg');
    expect($itemB['city'])->toBe('Dakar');
    // abs() on diffInDays: expiring in 20 days is NOT expiring soon
    expect($itemB['subscription']['is_expiring_soon'])->toBeFalse();

    $itemC = $items->firstWhere('user_id', $uAgent->id);
    // Expiring in 3 days IS expiring soon
    expect($itemC['subscription']['is_expiring_soon'])->toBeTrue();

    // Filter city
    $resCity = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/badges?city=Dakar');
    $cityItems = collect($resCity->json('data'));
    expect($cityItems->pluck('user_id'))->toContain($uBailleur->id);
    expect($cityItems->pluck('user_id'))->not->toContain($uAgent->id);

    // Filter cadence
    $resCadence = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/badges?cadence=yearly');
    $cadenceItems = collect($resCadence->json('data'));
    expect($cadenceItems->pluck('user_id'))->toContain($uAgent->id);
    expect($cadenceItems->pluck('user_id'))->not->toContain($uBailleur->id);

    // Sort by name
    $resSortName = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/badges?sort=name');
    $sortItems = collect($resSortName->json('data'));
    expect($sortItems->first()['user_id'])->toBe($uAgent->id); // Agent Beta before Bailleur Alpha
});

// 5. admin/kyc/refusals : filtres appeal_status, badge, reason et recours lié
it('filters refusals by appeal_status, badge, reason and includes linked appeal', function () {
    [$operator, $sessionId] = createEnhancementSession('verification');

    $u1 = User::factory()->create(['name' => 'Refused User 1']);
    $u2 = User::factory()->create(['name' => 'Refused User 2']);

    // Refusal 1: Rejected recently (within 30 days), no appeal filed -> appeal_status = open
    $sub1 = KycSubmission::create([
        'user_id' => $u1->id,
        'status' => KycStatus::Rejected->value,
        'profile_category' => 'landlord',
        'rejection_reason' => 'document_illegible',
        'decided_at' => now()->subDays(5),
    ]);

    // Refusal 2: Rejected and an appeal was filed -> appeal_status = appealed
    $sub2 = KycSubmission::create([
        'user_id' => $u2->id,
        'status' => KycStatus::Rejected->value,
        'profile_category' => 'agent',
        'rejection_reason' => 'identity_mismatch',
        'decided_at' => now()->subDays(10),
    ]);

    $appeal = ModerationAppeal::create([
        'user_id' => $u2->id,
        'sanction_type' => 'kyc_refusal',
        'sanction_reference' => (string) $sub2->id,
        'reason' => 'Nouvelle pièce valide fournie',
        'explanation' => 'Voici mon document lisible',
        'status' => AppealStatus::Pending,
    ]);

    // Test appeal_status=open
    $resOpen = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/refusals?appeal_status=open');
    $openItems = collect($resOpen->json('data'));
    expect($openItems->pluck('id'))->toContain($sub1->id);
    expect($openItems->pluck('id'))->not->toContain($sub2->id);

    // Test appeal_status=appealed
    $resAppealed = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/refusals?appeal_status=appealed');
    $appealedItems = collect($resAppealed->json('data'));
    expect($appealedItems->pluck('id'))->toContain($sub2->id);
    expect($appealedItems->pluck('id'))->not->toContain($sub1->id);

    // Linked appeal details
    $item2 = $appealedItems->firstWhere('id', $sub2->id);
    expect($item2['appeal_status'])->toBe('appealed');
    expect($item2['appeal']['reference'])->toBe($appeal->reference);
    expect($item2['appeal']['status'])->toBe('pending');

    // Filter badge=agent
    $resBadge = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/refusals?badge=agent');
    $badgeItems = collect($resBadge->json('data'));
    expect($badgeItems->pluck('id'))->toContain($sub2->id);
    expect($badgeItems->pluck('id'))->not->toContain($sub1->id);

    // Filter reason=document_illegible
    $resReason = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/refusals?reason=document_illegible');
    $reasonItems = collect($resReason->json('data'));
    expect($reasonItems->pluck('id'))->toContain($sub1->id);
    expect($reasonItems->pluck('id'))->not->toContain($sub2->id);
});

// 6. admin/kyc/queue : calcul dynamique de attempt, required docs et headline
it('calculates attempt dynamically and does not freeze it to 1', function () {
    [$operator, $sessionId] = createEnhancementSession('verification');

    $user = User::factory()->create(['name' => 'Multi Attempt User', 'city' => 'Abidjan']);

    // Attempt 1: created 2 days ago, replaced or rejected
    $sub1 = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'created_at' => now()->subDays(2),
    ]);

    // Attempt 2: created today
    $sub2 = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'created_at' => now(),
    ]);

    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/queue');

    $res->assertStatus(200);

    $items = collect($res->json('items'));
    $item1 = $items->firstWhere('id', (string) $sub1->id);
    $item2 = $items->firstWhere('id', (string) $sub2->id);

    expect($item1['attempt'])->toBe(1);
    expect($item2['attempt'])->toBe(2);
});

it('calculates required docs dynamically and displays id_valid when all expected docs received even if id_number is empty', function () {
    [$operator, $sessionId] = createEnhancementSession('verification');

    $userPassport = User::factory()->create(['name' => 'Passport Landlord', 'city' => 'Dakar']);
    $userCni = User::factory()->create(['name' => 'CNI Landlord', 'city' => 'Abidjan']);

    // Landlord with passport: requires 3 docs (passport, selfie, deed)
    $subPassport = KycSubmission::create([
        'user_id' => $userPassport->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'id_type' => 'passport',
        'id_number' => null, // Empty id_number!
    ]);

    // Add 3 documents to subPassport
    foreach (['front', 'selfie', 'deed'] as $kind) {
        KycDocument::create([
            'kyc_submission_id' => $subPassport->id,
            'user_id' => $userPassport->id,
            'kind' => $kind,
            'file_path' => "kyc/{$userPassport->id}/{$kind}.jpg",
            'original_name' => "{$kind}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'status' => 'pending',
        ]);
    }

    // Landlord with CNI: requires 4 docs (front, back, selfie, deed)
    $subCni = KycSubmission::create([
        'user_id' => $userCni->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'id_type' => 'cni',
        'id_number' => null, // Empty id_number!
    ]);

    // Add 4 documents to subCni
    foreach (['front', 'back', 'selfie', 'deed'] as $kind) {
        KycDocument::create([
            'kyc_submission_id' => $subCni->id,
            'user_id' => $userCni->id,
            'kind' => $kind,
            'file_path' => "kyc/{$userCni->id}/{$kind}.jpg",
            'original_name' => "{$kind}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'status' => 'pending',
        ]);
    }

    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/queue');

    $res->assertStatus(200);

    $items = collect($res->json('items'));
    $itemPassport = $items->firstWhere('id', (string) $subPassport->id);
    $itemCni = $items->firstWhere('id', (string) $subCni->id);

    // Passport landlord: required = 3, received = 3, headline = id_valid (NOT docs_missing)
    expect($itemPassport['docs']['required'])->toBe(3);
    expect($itemPassport['docs']['received'])->toBe(3);
    expect($itemPassport['headline']['key'])->toBe('id_valid');
    expect($itemPassport['headline']['ok'])->toBeTrue();

    // CNI landlord: required = 4, received = 4, headline = id_valid (NOT docs_missing despite empty id_number)
    expect($itemCni['docs']['required'])->toBe(4);
    expect($itemCni['docs']['received'])->toBe(4);
    expect($itemCni['headline']['key'])->toBe('id_valid');
    expect($itemCni['headline']['ok'])->toBeTrue();
});
