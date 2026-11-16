<?php

namespace Tests\Feature\Admin\Listing;

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Services\Admin\AdminListingControlService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    $role = Role::where('slug', 'admin')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'admin.listings.pg.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $this->sessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $this->sessionId,
        'user_id' => $this->operator->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);
});

test('GET admin/listings succeeds without 500 and returns valid tab counts and listings', function () {
    $owner = User::factory()->create();

    $l1 = Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Appartement vue mer',
        'mode' => ListingMode::Rent,
        'kind' => ListingKind::Flat,
        'status' => ListingStatus::Published,
    ]);

    $l2 = Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Villa avec piscine',
        'mode' => ListingMode::Sale,
        'kind' => ListingKind::Villa,
        'status' => ListingStatus::Draft,
    ]);

    // Sans filtre de statut
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/listings');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $counts = $response->json('meta.counts');
    expect($counts)->toBeArray()
        ->and($counts['all'])->toBe(2)
        ->and($counts['published'])->toBe(1)
        ->and($counts['draft'])->toBe(1);

    // Avec filtre de statut
    $filterResponse = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/listings?status=published');

    $filterResponse->assertOk()
        ->assertJsonPath('success', true);

    $filteredData = $filterResponse->json('data');
    expect($filteredData)->toHaveCount(1)
        ->and($filteredData[0]['id'])->toBe($l1->id);

    // Les compteurs reflètent l'ensemble des statuts même avec un filtre appliqué
    $filterCounts = $filterResponse->json('meta.counts');
    expect($filterCounts['all'])->toBe(2)
        ->and($filterCounts['published'])->toBe(1)
        ->and($filterCounts['draft'])->toBe(1);
});

test('tab counts SQL query contains ONLY status and aggregate in SELECT and is strict postgres compliant', function () {
    $owner = User::factory()->create();
    Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'status' => ListingStatus::Published,
    ]);

    $executedQueries = [];
    DB::listen(function ($query) use (&$executedQueries) {
        $executedQueries[] = [
            'sql' => $query->sql,
            'time' => $query->time,
        ];
    });

    $service = app(AdminListingControlService::class);
    $result = $service->getListings([], 20);

    expect($result['counts'])->toHaveKey('all')
        ->and($result['paginator'])->not->toBeNull();

    // Trouver la requête de GROUP BY status
    $groupByQueries = array_filter($executedQueries, function ($q) {
        return Str::contains(strtolower($q['sql']), 'group by')
            && Str::contains(strtolower($q['sql']), 'status');
    });

    expect($groupByQueries)->not->toBeEmpty();

    foreach ($groupByQueries as $q) {
        $sql = strtolower($q['sql']);
        // La requête GROUP BY ne doit contenir ni listings.id ni conversations_count dans le SELECT
        expect($sql)->not->toContain('listings.id')
            ->and($sql)->not->toContain('conversations_count')
            ->and($sql)->not->toContain('listings.*');

        // Vérifier que le SELECT contient bien status et count(*)
        expect($sql)->toContain('status')
            ->and($sql)->toContain('count(*)');
    }
});
