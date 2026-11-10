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

it('supports claim_kind on deposit, request_info decision to under_review, status[]/decided, claim_kind[], country[] filters, and 90-day meta metrics', function () {
    [$operator, $sessionId, $csrfToken] = createMediaSession('moderation');

    $author = User::factory()->create(['country_code' => 'CM']);
    $post = Post::factory()->create(['user_id' => $author->id]);

    // 1. Dépôt via le formulaire public avec claim_kind et country
    $depositResponse = $this->postJson('/api/legal/copyright-claims', [
        'claimant_name' => 'Marque Déposée SA',
        'claimant_email' => 'juridique@marque.cm',
        'claim_kind' => 'trademark',
        'country' => 'cm',
        'content_type' => 'post',
        'content_id' => (string) $post->id,
        'work_title' => 'Logo Officiel Marque',
        'reason' => 'Utilisation commerciale non autorisée de notre marque déposée.',
    ]);

    $depositResponse->assertStatus(201)
        ->assertJsonPath('data.claim_kind', 'trademark')
        ->assertJsonPath('data.country', 'CM')
        ->assertJsonPath('data.status', 'pending');

    $claimRef = $depositResponse->json('data.reference');

    // 2. Décision request_info (avec message au propriétaire des droits) -> passe en under_review
    $requestInfoResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/rights/{$claimRef}/decision", [
            'decision' => 'request_info',
            'message' => 'Veuillez fournir le certificat d\'enregistrement OAPI de la marque.',
        ]);

    $requestInfoResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'under_review')
        ->assertJsonPath('data.decision', 'request_info')
        ->assertJsonPath('data.message', 'Veuillez fournir le certificat d\'enregistrement OAPI de la marque.');

    // Créer d'autres réclamations décidées (approved et rejected) avec durées connues sur 90 jours
    $baseTime = now()->subDays(10);

    $approvedClaim = CopyrightClaim::create([
        'claimant_name' => 'Photographe Pro',
        'claimant_email' => 'photo@studio.ci',
        'claim_kind' => 'media',
        'country' => 'CI',
        'content_type' => 'post',
        'content_id' => '501',
        'work_title' => 'Photo Abidjan',
        'reason' => 'Copie intégrale de cliché.',
        'status' => 'approved',
        'decision' => 'content_removed',
        'decision_notes' => 'Preuve EXIF fournie.',
        'resolved_by' => $operator->id,
        'created_at' => $baseTime,
        'resolved_at' => $baseTime->copy()->addHours(4), // 4h
    ]);
    $approvedClaim->timestamps = false;
    $approvedClaim->created_at = $baseTime;
    $approvedClaim->save();

    $rejectedClaim = CopyrightClaim::create([
        'claimant_name' => 'Auteur Texte',
        'claimant_email' => 'auteur@texte.sn',
        'claim_kind' => 'impersonation',
        'country' => 'SN',
        'content_type' => 'page',
        'content_id' => '502',
        'work_title' => 'Identité Officielle',
        'reason' => 'Usurpation alléguée.',
        'status' => 'rejected',
        'decision' => 'dismissed',
        'decision_notes' => 'Aucune usurpation constatée.',
        'resolved_by' => $operator->id,
        'created_at' => $baseTime,
        'resolved_at' => $baseTime->copy()->addHours(8), // 8h
    ]);
    $rejectedClaim->timestamps = false;
    $rejectedClaim->created_at = $baseTime;
    $rejectedClaim->save();

    // 3. Lister toutes les réclamations décidées via status=decided et status[]
    $decidedResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/rights?status=decided');

    $decidedResponse->assertStatus(200)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('meta.counts.under_review', 1)
        ->assertJsonPath('meta.counts.approved', 1)
        ->assertJsonPath('meta.counts.rejected', 1)
        ->assertJsonPath('meta.counts.decided', 2)
        ->assertJsonPath('meta.median_decision_hours', 6)
        ->assertJsonPath('meta.accept_rate', 50);

    $statusArrayResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/rights?status[]=approved&status[]=rejected');

    $statusArrayResponse->assertStatus(200)
        ->assertJsonPath('total', 2);

    // 4. Filtres claim_kind[] et country[]
    $kindResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/rights?status=all&claim_kind[]=trademark&claim_kind[]=impersonation');

    $kindResponse->assertStatus(200)
        ->assertJsonPath('total', 2);

    $countryResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/rights?status=all&country[]=CM&country[]=CI');

    $countryResponse->assertStatus(200)
        ->assertJsonPath('total', 2);
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
