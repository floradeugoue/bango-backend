<?php

namespace Tests\Feature\Admin\Finance;

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditWallet;
use App\Models\Square\Post;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createBoostSession(string $roleSlug = 'finance'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::where('email', 'aminata.traore@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => "op.boost.{$roleSlug}.".uniqid().'@bango.app',
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

it('denies access to campaigns without admin session', function () {
    $response = $this->getJson('/api/admin/campaigns');
    $response->assertStatus(401);
});

it('denies access to operator without boost permissions', function () {
    $role = Role::where('slug', 'support')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'sup.test.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator->id,
        'csrf_token' => bin2hex(random_bytes(32)),
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/campaigns');

    $response->assertStatus(403)
        ->assertJsonFragment(['code' => 'ADMIN_PERMISSION_DENIED']);
});

it('lists boost campaigns with budgets and spend (5.1)', function () {
    [$aminata, $sessionId] = createBoostSession('finance');

    $advertiser = User::factory()->create(['name' => 'Annonceur Pro']);
    $post = Post::create([
        'user_id' => $advertiser->id,
        'body' => 'Post sponsorisé Square',
        'visibility' => 'public',
    ]);

    Campaign::create([
        'user_id' => $advertiser->id,
        'boostable_type' => Post::class,
        'boostable_id' => $post->id,
        'objective' => CampaignObjective::Reach,
        'budget_amount' => 50000,
        'spent_amount' => 20000,
        'duration_days' => 7,
        'status' => CampaignStatus::Active,
        'budget_currency' => 'XAF',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/campaigns');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'total'],
        ]);

    expect($response->json('data.0.budget_amount'))->toBe(50000);
    expect($response->json('data.0.spent_amount'))->toBe(20000);
    expect($response->json('data.0.remaining_budget'))->toBe(30000);
});

it('returns detailed campaign information (5.2)', function () {
    [$aminata, $sessionId] = createBoostSession('finance');

    $advertiser = User::factory()->create(['name' => 'Annonceur Detail']);
    $post = Post::create([
        'user_id' => $advertiser->id,
        'body' => 'Post sponsorisé test',
        'visibility' => 'public',
    ]);

    $campaign = Campaign::create([
        'user_id' => $advertiser->id,
        'boostable_type' => Post::class,
        'boostable_id' => $post->id,
        'objective' => CampaignObjective::Engagement,
        'budget_amount' => 10000,
        'spent_amount' => 4000,
        'duration_days' => 5,
        'status' => CampaignStatus::Active,
        'budget_currency' => 'XAF',
        'impressions_count' => 1250,
        'clicks_count' => 85,
        'reach_count' => 950,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/campaigns/{$campaign->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', (string) $campaign->id)
        ->assertJsonPath('data.budget_amount', 10000)
        ->assertJsonPath('data.remaining_budget', 6000)
        ->assertJsonPath('data.metrics.impressions', 1250);
});

it('lists campaign budget refunds from credit ledger (5.3)', function () {
    [$aminata, $sessionId] = createBoostSession('finance');

    $advertiser = User::factory()->create();
    $wallet = CreditWallet::create([
        'user_id' => $advertiser->id,
        'balance' => 8000,
        'currency' => 'XAF',
    ]);

    $wallet->transactions()->create([
        'user_id' => $advertiser->id,
        'type' => CreditTransactionType::CampaignRefund,
        'amount' => 8000,
        'balance_before' => 0,
        'balance_after' => 8000,
        'source' => 'boost_restitution',
        'reference' => 'RESTIT-CAMP-1',
        'description' => 'Restitution suite à suspension',
        'created_at' => now(),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/campaigns/refunds');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta',
        ]);

    expect($response->json('data.0.amount'))->toBe(8000);
});

it('previews unspent restitutable budget without modifying data (5.4)', function () {
    [$aminata, $sessionId, $csrfToken] = createBoostSession('finance');

    $advertiser = User::factory()->create();
    $post = Post::create([
        'user_id' => $advertiser->id,
        'body' => 'Post preview suspension',
        'visibility' => 'public',
    ]);

    $campaign = Campaign::create([
        'user_id' => $advertiser->id,
        'boostable_type' => Post::class,
        'boostable_id' => $post->id,
        'objective' => CampaignObjective::Reach,
        'budget_amount' => 40000,
        'spent_amount' => 15000,
        'duration_days' => 10,
        'status' => CampaignStatus::Active,
        'budget_currency' => 'XAF',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/campaigns/suspend/preview', [
            'campaign_id' => $campaign->id,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.budget_amount', 40000)
        ->assertJsonPath('data.spent_amount', 15000)
        ->assertJsonPath('data.unspent_amount', 25000)
        ->assertJsonPath('data.restitutable_amount', 25000)
        ->assertJsonPath('data.already_refunded', false);

    // Vérifier que la campagne reste Active et sans modification
    $campaign->refresh();
    expect($campaign->status)->toBe(CampaignStatus::Active);
});

it('suspends campaign and credits unspent budget to advertiser wallet (5.5)', function () {
    [$aminata, $sessionId, $csrfToken] = createBoostSession('finance');

    $advertiser = User::factory()->create();
    CreditWallet::create([
        'user_id' => $advertiser->id,
        'balance' => 0,
        'currency' => 'XAF',
    ]);

    $post = Post::create([
        'user_id' => $advertiser->id,
        'body' => 'Post à suspendre',
        'visibility' => 'public',
    ]);

    $campaign = Campaign::create([
        'user_id' => $advertiser->id,
        'boostable_type' => Post::class,
        'boostable_id' => $post->id,
        'objective' => CampaignObjective::Reach,
        'budget_amount' => 30000,
        'spent_amount' => 10000,
        'duration_days' => 7,
        'status' => CampaignStatus::Active,
        'budget_currency' => 'XAF',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/campaigns/suspend', [
            'campaign_id' => $campaign->id,
            'reason' => 'Contenu non conforme aux règles de diffusion publicitaire.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'suspended')
        ->assertJsonPath('data.restituted_amount', 20000);

    // Vérifier l'état de la campagne
    $campaign->refresh();
    expect($campaign->status)->toBe(CampaignStatus::Suspended);

    // Vérifier que le portefeuille a été crédité du reliquat (20000 FCFA = 20000 Crédits)
    $wallet = CreditWallet::where('user_id', $advertiser->id)->first();
    expect($wallet->balance)->toBe(20000);
});

it('sends explanatory notification to advertiser (5.6)', function () {
    [$aminata, $sessionId, $csrfToken] = createBoostSession('finance');

    $advertiser = User::factory()->create();
    $post = Post::create([
        'user_id' => $advertiser->id,
        'body' => 'Post pour notification',
        'visibility' => 'public',
    ]);

    $campaign = Campaign::create([
        'user_id' => $advertiser->id,
        'boostable_type' => Post::class,
        'boostable_id' => $post->id,
        'objective' => CampaignObjective::Reach,
        'budget_amount' => 15000,
        'spent_amount' => 5000,
        'duration_days' => 5,
        'status' => CampaignStatus::Active,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/campaigns/{$campaign->id}/notify", [
            'message' => 'Votre campagne a été mise en pause en raison d\'une vérification technique.',
            'language' => 'fr',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.language', 'fr');
});

it('exports campaigns into secure CSV with formula protection (5.7)', function () {
    [$aminata, $sessionId, $csrfToken] = createBoostSession('finance');

    $advertiser = User::factory()->create(['name' => '=HYPERLINK("http://evil.com")']);
    $post = Post::create([
        'user_id' => $advertiser->id,
        'body' => 'Post export test',
        'visibility' => 'public',
    ]);

    Campaign::create([
        'user_id' => $advertiser->id,
        'boostable_type' => Post::class,
        'boostable_id' => $post->id,
        'objective' => CampaignObjective::Reach,
        'budget_amount' => 20000,
        'spent_amount' => 5000,
        'duration_days' => 5,
        'status' => CampaignStatus::Active,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/campaigns/exports');

    $response->assertStatus(200);
    $content = $response->streamedContent();

    // Protection anti-injection CSV sur le nom de l'annonceur
    expect($content)->toContain("'=HYPERLINK");
});
