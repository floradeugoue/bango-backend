<?php

namespace Tests\Feature\Admin\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Models\Page\PageMember;
use App\Models\Square\Post;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\Support\Report;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);
});

function createPageAdminSession(string $roleSlug = 'direction'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => "op.page.{$roleSlug}.".uniqid().'@bango.app',
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

it('allows admin to list professional pages with filters and counters', function () {
    [$operator, $sessionId, $csrfToken] = createPageAdminSession('direction');

    $owner1 = User::factory()->create(['name' => 'Jean Dupont']);
    $owner2 = User::factory()->create(['name' => 'Paul Biya']);

    $page1 = Page::factory()->create([
        'owner_id' => $owner1->id,
        'name' => 'Boutique Alpha Tech',
        'handle' => 'alpha_tech',
        'city' => 'Douala',
        'sector' => 'services',
        'status' => 'active',
        'certification_status' => PageCertificationStatus::Approved->value,
    ]);

    $page2 = Page::factory()->create([
        'owner_id' => $owner2->id,
        'name' => 'Restaurant Le Palmier',
        'handle' => 'le_palmier',
        'city' => 'Yaoundé',
        'sector' => 'commerce',
        'status' => 'suspended',
        'certification_status' => 'none',
    ]);

    // Test list all
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/pages');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'items',
            'counts' => ['all', 'active', 'suspended', 'certified', 'pending_certification'],
            'meta',
        ]);

    expect($response->json('counts.all'))->toBe(2);
    expect($response->json('counts.active'))->toBe(1);
    expect($response->json('counts.suspended'))->toBe(1);
    expect($response->json('counts.certified'))->toBe(1);

    // Test search filter
    $searchResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/pages?q=Alpha');

    $searchResponse->assertOk();
    expect(count($searchResponse->json('items')))->toBe(1);
    expect($searchResponse->json('items.0.handle'))->toBe('alpha_tech');

    // Test city filter
    $cityResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/pages?city=Yaoundé');

    $cityResponse->assertOk();
    expect(count($cityResponse->json('items')))->toBe(1);
    expect($cityResponse->json('items.0.handle'))->toBe('le_palmier');

    // Test certification status filter
    $certResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/pages?certification_status=approved');

    $certResponse->assertOk();
    expect(count($certResponse->json('items')))->toBe(1);
    expect($certResponse->json('items.0.id'))->toBe($page1->id);
});

it('allows admin to view comprehensive details of a professional page', function () {
    [$operator, $sessionId, $csrfToken] = createPageAdminSession('direction');

    $owner = User::factory()->create([
        'name' => 'Patron Entreprise',
        'email' => 'patron@entreprise.cm',
    ]);

    $memberUser = User::factory()->create([
        'name' => 'Manager Equipe',
        'email' => 'manager@entreprise.cm',
    ]);

    $page = Page::factory()->create([
        'owner_id' => $owner->id,
        'name' => 'Entreprise Globale SARL',
        'handle' => 'entreprise_globale',
        'city' => 'Douala',
        'sector' => 'commerce',
        'status' => 'active',
        'certification_status' => PageCertificationStatus::Approved->value,
    ]);

    // Member
    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $memberUser->id,
        'role' => PageRole::Editor->value,
        'status' => PageMemberStatus::Accepted->value,
        'is_active' => true,
    ]);

    // Certification
    PageCertification::factory()->create([
        'page_id' => $page->id,
        'submitted_by_id' => $owner->id,
        'rccm_number' => 'RC/DLA/2026/B/1234',
        'status' => PageCertificationStatus::Approved->value,
    ]);

    // Active subscription
    $product = SubscriptionProduct::where('code', 'page_pro')->first();
    $price = SubscriptionPrice::where('product_id', $product?->id)->first();

    Subscription::create([
        'user_id' => $owner->id,
        'page_id' => $page->id,
        'product_id' => $product->id,
        'price_id' => $price?->id,
        'state' => 'active',
        'badge_type' => 'verified_business',
        'cadence' => 'monthly',
        'amount' => 15000,
        'currency' => 'XAF',
        'current_period_start' => now()->subDays(5),
        'current_period_end' => now()->addDays(25),
    ]);

    // Post
    Post::factory()->create([
        'user_id' => $owner->id,
        'page_id' => $page->id,
        'body' => 'Nouvel arrivage chez Entreprise Globale !',
    ]);

    // Listing
    Listing::factory()->create([
        'owner_id' => $owner->id,
        'page_id' => $page->id,
        'title' => 'Produit Premium',
        'status' => 'published',
    ]);

    // Report against the page
    Report::create([
        'reference' => 'REP-PAGE-'.bin2hex(random_bytes(4)),
        'reporter_id' => $owner->id,
        'reported_user_id' => $owner->id,
        'reported_page_id' => $page->id,
        'reportable_type' => Page::class,
        'reportable_id' => (string) $page->id,
        'reason' => 'spam',
        'status' => 'pending',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/pages/{$page->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.page.id', $page->id)
        ->assertJsonPath('data.owner.id', $owner->id)
        ->assertJsonPath('data.certification.rccm_number', 'RC/DLA/2026/B/1234')
        ->assertJsonStructure([
            'success',
            'data' => [
                'page',
                'owner',
                'team',
                'certification',
                'certifications_history',
                'subscription',
                'subscriptions_history',
                'recent_posts',
                'reports',
                'stats' => ['team_count', 'posts_count', 'listings_count', 'reports_count', 'followers_count'],
            ],
        ]);

    expect($response->json('data.stats.team_count'))->toBe(1);
    expect($response->json('data.stats.posts_count'))->toBe(1);
    expect($response->json('data.stats.reports_count'))->toBe(1);
    expect(count($response->json('data.team')))->toBe(1);
    expect($response->json('data.team.0.user.name'))->toBe('Manager Equipe');
});
