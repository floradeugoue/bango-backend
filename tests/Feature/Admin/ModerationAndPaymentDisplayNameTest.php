<?php

namespace Tests\Feature\Admin;

use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Payment\PaymentTransaction;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\Support\Report;
use App\Models\User;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(ModerationRuleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $this->adminOperator = User::factory()->create([
        'email' => 'admin.test.'.uniqid().'@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $this->sessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $this->sessionId,
        'user_id' => $this->adminOperator->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);
});

test('transactions list and show expose display_name in addition to name and full profile fields', function () {
    $user = User::factory()->create([
        'name' => 'pay.a+1793667282676',
        'display_name' => 'Paul Paiement',
        'handle' => 'paulpaiement',
        'city' => 'Douala',
        'avatar_url' => 'https://cdn.bango.app/avatars/paul.webp',
        'badge' => 'landlord',
    ]);

    $tx = PaymentTransaction::create([
        'reference' => 'TX-PAUL-001',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'amount' => 15000,
        'fee_amount' => 300,
        'total_amount' => 15300,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'subscription',
        'provider' => 'monetbil',
    ]);

    // 1. Liste des transactions GET /api/admin/payments/transactions
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/transactions');

    $response->assertOk();
    $txData = collect($response->json('data'))->firstWhere('reference', 'TX-PAUL-001');
    expect($txData)->not->toBeNull()
        ->and($txData['user']['name'])->toBe('pay.a+1793667282676')
        ->and($txData['user']['display_name'])->toBe('Paul Paiement')
        ->and($txData['user']['handle'])->toBe('paulpaiement')
        ->and($txData['user']['city'])->toBe('Douala')
        ->and($txData['user']['badge'])->toBe('landlord')
        ->and($txData['user']['avatar_url'])->toBe('https://cdn.bango.app/avatars/paul.webp');

    // 2. Détail d'une transaction GET /api/admin/payments/transactions/{id}
    $showRes = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson("/api/admin/payments/transactions/{$tx->id}");

    $showRes->assertOk();
    $showUser = $showRes->json('data.user');
    expect($showUser['name'])->toBe('pay.a+1793667282676')
        ->and($showUser['display_name'])->toBe('Paul Paiement')
        ->and($showUser['handle'])->toBe('paulpaiement')
        ->and($showUser['city'])->toBe('Douala')
        ->and($showUser['badge'])->toBe('landlord');
});

test('moderation queue exposes target with excerpt, thumbnail_url, display_name, city, badge and human target_label', function () {
    // 1. Compte signalé : nom technique vs display_name
    $reportedUser = User::factory()->create([
        'name' => 'sup.b+1793885855659',
        'display_name' => 'Jean Signalé',
        'handle' => 'jeansignale',
        'city' => 'Yaoundé',
        'badge' => 'agent',
        'avatar_url' => 'https://cdn.bango.app/avatars/jean.webp',
        'bio' => 'Bio de Jean le courtier immobilier',
    ]);

    $reporter = User::factory()->create([
        'display_name' => 'Signalant Anonyme',
    ]);

    $accountReport = Report::create([
        'reference' => 'MOD-2026-ACC01',
        'reporter_id' => $reporter->id,
        'reportable_type' => User::class,
        'reportable_id' => (string) $reportedUser->id,
        'reason' => ReportReason::Fraud,
        'details' => 'Faux profil suspect',
        'status' => ReportStatus::Pending,
        'metadata' => ['target_label' => 'sup.b+1793885855659'],
    ]);

    // 2. Publication signalée avec média
    $postAuthor = User::factory()->create([
        'name' => 'post.author',
        'display_name' => 'Marc Publication',
        'city' => 'Douala',
        'badge' => 'none',
    ]);

    $post = Post::create([
        'user_id' => $postAuthor->id,
        'body' => 'Voici un extrait de texte signalé pour modération immédiate.',
        'place_city' => 'Douala',
    ]);

    PostMedia::create([
        'post_id' => $post->id,
        'media_type' => 'photo',
        'media_url' => 'https://cdn.bango.app/posts/image1.jpg',
        'poster_url' => 'https://cdn.bango.app/posts/thumb1.jpg',
        'display_order' => 1,
    ]);

    $postReport = Report::create([
        'reference' => 'MOD-2026-POST01',
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => (string) $post->id,
        'reason' => ReportReason::HateSpeech,
        'details' => 'Contenu injurieux',
        'status' => ReportStatus::Pending,
    ]);

    // 3. Appel de GET /api/admin/moderation/queue
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/moderation/queue?tab=all');

    $response->assertOk();
    $items = collect($response->json('items'));

    // Vérification compte signalé
    $accItem = $items->firstWhere('reference', 'MOD-2026-ACC01');
    expect($accItem)->not->toBeNull()
        ->and($accItem['target'])->toMatchArray([
            'type' => 'account',
            'id' => (string) $reportedUser->id,
            'display_name' => 'Jean Signalé',
            'handle' => 'jeansignale',
            'avatar_url' => 'https://cdn.bango.app/avatars/jean.webp',
            'city' => 'Yaoundé',
            'badge' => 'agent',
            'excerpt' => 'Bio de Jean le courtier immobilier',
            'thumbnail_url' => 'https://cdn.bango.app/avatars/jean.webp',
        ])
        // metadata.target_label résout le nom humain
        ->and($accItem['metadata']['target_label'])->toBe('Jean Signalé');

    // Vérification publication signalée
    $pItem = $items->firstWhere('reference', 'MOD-2026-POST01');
    expect($pItem)->not->toBeNull()
        ->and($pItem['target']['type'])->toBe('post')
        ->and($pItem['target']['id'])->toBe((string) $post->id)
        ->and($pItem['target']['display_name'])->toBe('Marc Publication')
        ->and($pItem['target']['city'])->toBe('Douala')
        ->and($pItem['target']['excerpt'])->toBe('Voici un extrait de texte signalé pour modération immédiate.')
        ->and($pItem['target']['thumbnail_url'])->toBe('https://cdn.bango.app/posts/thumb1.jpg');
});
