<?php

namespace Tests\Feature\Admin\Moderation;

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Reel\Sound;
use App\Models\Square\Hashtag;
use App\Models\Square\Post;
use App\Models\Support\CopyrightClaim;
use App\Models\User;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(ModerationRuleSeeder::class);
});

function createMediaSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.media.'.uniqid().'@bango.app',
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

it('lists copyright claims and applies copyright decisions', function () {
    [$operator, $sessionId, $csrfToken] = createMediaSession('moderation');

    $author = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $author->id]);

    $claim = CopyrightClaim::create([
        'claimant_name' => 'Artiste Ayant Droit',
        'claimant_email' => 'artiste@music.com',
        'content_type' => 'post',
        'content_id' => (string) $post->id,
        'work_title' => 'Chanson Originale',
        'reason' => 'Utilisation sans autorisation de mes œuvres.',
        'status' => 'pending',
    ]);

    // 1. Consultation de la file
    $queueResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/rights');

    $queueResponse->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data', 'pagination']);

    // 2. Prise de décision de retrait
    $decisionResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/rights/{$claim->reference}/decision", [
            'decision' => 'content_removed',
            'decision_notes' => 'Preuve de titularité établie, retrait du contenu.',
        ]);

    $decisionResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'decision' => 'content_removed',
                'status' => 'approved',
            ],
        ]);

    // Vérifier que le post a été supprimé
    expect(Post::find($post->id))->toBeNull();
});

it('lists sounds and applies moderation actions on the audio catalog', function () {
    [$operator, $sessionId, $csrfToken] = createMediaSession('moderation');

    $sound = Sound::create([
        'id' => 'sound-test-123',
        'title' => 'Beat Bikutsi',
        'artist' => 'Producteur Cameroun',
        'audio_url' => 'sounds/test.mp3',
        'rights_status' => 'pending',
        'is_published' => false,
    ]);

    // 1. Consulter les sons
    $listResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/sounds');

    $listResponse->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data', 'pagination']);

    // 2. Valider le son
    $validateResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/sounds/{$sound->id}/action", [
            'action' => 'validate',
            'reason' => 'Droits d’auteur vérifiés auprès du producteur',
        ]);

    $validateResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    $freshSound = $sound->fresh();
    expect($freshSound->rights_status)->toBe('verified')
        ->and($freshSound->is_published)->toBeTrue();

    // 3. Masquer/désactiver le son
    $disableResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/sounds/{$sound->id}/action", [
            'action' => 'disable',
            'reason' => 'Signalement pour contestation de licence',
        ]);

    $disableResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    expect($sound->fresh()->is_removed)->toBeTrue();
});

it('excludes a hashtag from trends without deleting source publications and restores it', function () {
    [$operator, $sessionId, $csrfToken] = createMediaSession('moderation');

    $author = User::factory()->create();
    $hashtag = Hashtag::create([
        'name' => 'BadTrend',
        'normalized_name' => 'badtrend',
        'use_count' => 50,
    ]);

    // Publication existante avec ce hashtag
    $post = Post::factory()->create([
        'user_id' => $author->id,
        'body' => 'Regardez ce post avec #BadTrend',
    ]);

    // 1. Exclure le hashtag des tendances
    $excludeResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/moderation/trends/exclusions', [
            'hashtag' => 'BadTrend',
            'reason' => 'Hashtag utilisé pour relayer des attaques ciblées',
        ]);

    $excludeResponse->assertStatus(201)
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('hashtag_exclusions', [
        'hashtag_name' => 'badtrend',
        'is_active' => true,
    ]);

    // RÈGLE MÉTIER OBLIGATOIRE : La publication source NE DOIT PAS être supprimée
    expect(Post::find($post->id))->not->toBeNull();

    // 2. Consultation des tendances
    $trendsResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/trends');

    $trendsResponse->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data' => ['active_exclusions']]);

    // 3. Restauration de l'exclusion
    $restoreResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/moderation/exclusions/restore', [
            'hashtag' => 'BadTrend',
        ]);

    $restoreResponse->assertStatus(200)
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('hashtag_exclusions', [
        'hashtag_name' => 'badtrend',
        'is_active' => false,
    ]);
});
