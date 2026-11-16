<?php

namespace Tests\Feature\Admin\Settings;

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Legal\LegalDocument;
use App\Models\Legal\TermsAcceptance;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'admin.legal.'.uniqid().'@bango.app',
        'role_id' => $adminRole->id,
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

test('GET admin/settings/legal rend acceptances_count et le nombre de comptes concernes par version', function () {
    $userRole = Role::where('slug', 'user')->firstOrFail();

    // 1. Création d'un document légal (CGU v3.1)
    $cguDoc = LegalDocument::create([
        'slug' => 'cgu',
        'version' => '3.1',
        'locale' => 'fr',
        'title' => 'Conditions Générales d’Utilisation v3.1',
        'content_markdown' => '# CGU 3.1',
        'effective_date' => now()->subMonths(1),
        'is_published' => true,
        'published_at' => now()->subMonths(1),
        'is_mandatory' => true,
        'created_by' => $this->operator->id,
    ]);

    // 2. Création de 101 utilisateurs ayant accepté la CGU 3.1
    $users = User::factory()->count(101)->create(['role_id' => $userRole->id, 'status' => 'active']);

    foreach ($users as $u) {
        TermsAcceptance::create([
            'user_id' => $u->id,
            'legal_document_id' => $cguDoc->id,
            'version' => '3.1',
            'document_slug' => 'cgu',
            'locale' => 'fr',
            'accepted_at' => now(),
        ]);
    }

    // 3. Appel à la route GET /api/admin/settings/legal
    $res = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/settings/legal');

    $res->assertStatus(200);

    $items = collect($res->json('data'));
    $cguItem = $items->firstWhere('id', $cguDoc->id);

    expect($cguItem)->not->toBeNull()
        ->and($cguItem['version'])->toBe('3.1')
        ->and($cguItem['acceptances_count'])->toBe(101)
        ->and($cguItem['concerned_accounts_count'])->toBeGreaterThanOrEqual(101)
        ->and($cguItem['acceptance']['accepted'])->toBe(101)
        ->and($cguItem['acceptance']['total'])->toBeGreaterThanOrEqual(101)
        ->and($cguItem['acceptance']['audience'])->toBe('all');
});

test('GET admin/settings/legal rend acceptances_count meme si lier via document_slug et version sans legal_document_id', function () {
    $userRole = Role::where('slug', 'user')->firstOrFail();

    $doc = LegalDocument::create([
        'slug' => 'privacy-policy',
        'version' => '2.5',
        'locale' => 'fr',
        'title' => 'Politique de Confidentialité v2.5',
        'content_markdown' => '# Privacy 2.5',
        'effective_date' => now()->subDays(10),
        'is_published' => true,
        'published_at' => now()->subDays(10),
        'is_mandatory' => true,
        'created_by' => $this->operator->id,
    ]);

    $users = User::factory()->count(5)->create(['role_id' => $userRole->id, 'status' => 'active']);

    foreach ($users as $u) {
        TermsAcceptance::create([
            'user_id' => $u->id,
            'legal_document_id' => null, // non lié directement par ID
            'version' => '2.5',
            'document_slug' => 'privacy-policy',
            'locale' => 'fr',
            'accepted_at' => now(),
        ]);
    }

    $res = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/settings/legal');

    $res->assertStatus(200);

    $items = collect($res->json('data'));
    $item = $items->firstWhere('id', $doc->id);

    expect($item)->not->toBeNull()
        ->and($item['acceptances_count'])->toBe(5)
        ->and($item['acceptance']['accepted'])->toBe(5);
});
