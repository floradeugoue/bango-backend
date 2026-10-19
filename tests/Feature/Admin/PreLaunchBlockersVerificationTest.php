<?php

namespace Tests\Feature\Admin;

use App\Enums\Kyc\KycStatus;
use App\Enums\Listing\ListingStatus;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminAccessRequest;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Kyc\KycSubmission;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\Square\Post;
use App\Models\Support\Report;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use App\Notifications\Kyc\KycDecisionNotification;
use App\Notifications\Listing\ListingDecisionNotification;
use App\Notifications\Support\ModerationDecisionNotification;
use App\Services\Auth\TotpService;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(ModerationRuleSeeder::class);
});

function createTestAdminSession(string $roleSlug = 'direction'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => "op.{$roleSlug}.".uniqid().'@bango.app',
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

/*
|--------------------------------------------------------------------------
| ISSUE 10 : Enrôlement 2FA Opérateur & Gestion Direction
|--------------------------------------------------------------------------
*/
it('completes the 3-step 2FA onboarding flow and allows login only when fully enrolled', function () {
    [$directionOp, $dirSession, $dirCsrf] = createTestAdminSession('direction');

    // 1. La direction invite un collaborateur
    $inviteResponse = $this->withUnencryptedCookie('bango_admin_session', $dirSession)
        ->withHeader('X-CSRF-TOKEN', $dirCsrf)
        ->postJson('/api/admin/invitations', [
            'email' => 'nouveau.collaborateur@bango.app',
            'role' => 'moderation',
            'expires_in_days' => 3,
        ]);

    $inviteResponse->assertStatus(201)
        ->assertJsonStructure(['invitation' => ['token', 'email', 'role']]);

    $rawToken = $inviteResponse->json('invitation.token');

    // 2. Étape 1 : Définition du mot de passe -> rend le secret TOTP et l'URI QR
    $step1Response = $this->postJson("/api/auth/invitations/{$rawToken}/password", [
        'password' => 'BangoSecurePassword2026!',
        'password_confirmation' => 'BangoSecurePassword2026!',
        'name' => 'Jean Collaborateur',
    ]);

    $step1Response->assertStatus(200)
        ->assertJsonStructure(['success', 'totp_secret', 'qr_code_uri', 'message'])
        ->assertJsonPath('success', true);

    $totpSecret = $step1Response->json('totp_secret');
    expect($totpSecret)->not->toBeEmpty();

    // 3. Étape 2 : Validation TOTP avec code invalide -> 422
    $invalidTotpResponse = $this->postJson("/api/auth/invitations/{$rawToken}/totp", [
        'code' => '000000',
    ]);
    $invalidTotpResponse->assertStatus(422);

    // Validation TOTP avec code valide -> rend les 10 codes de secours
    $totpService = app(TotpService::class);
    $validTotp = $totpService->calculateTotpForStep($totpSecret, (int) floor(time() / 30));

    $step2Response = $this->postJson("/api/auth/invitations/{$rawToken}/totp", [
        'code' => $validTotp,
    ]);

    $step2Response->assertStatus(200)
        ->assertJsonStructure(['success', 'backup_codes', 'message'])
        ->assertJsonPath('success', true);

    $backupCodes = $step2Response->json('backup_codes');
    expect($backupCodes)->toBeArray()->toHaveCount(10);

    // 4. Étape 3 : Finalisation de l'activation
    $step3Response = $this->postJson("/api/auth/invitations/{$rawToken}/complete");
    $step3Response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('email', 'nouveau.collaborateur@bango.app');

    // Vérifier en base
    $user = User::where('email', 'nouveau.collaborateur@bango.app')->first();
    expect($user)->not->toBeNull()
        ->and($user->status)->toBe('active')
        ->and($user->role->slug)->toBe('moderation')
        ->and($user->two_factor_method)->toBe('totp')
        ->and($user->two_factor_secret)->not->toBeNull()
        ->and(Crypt::decryptString($user->two_factor_secret))->toBe($totpSecret)
        ->and($user->backup_codes)->toHaveCount(10);

    // Réutilisation du token impossible
    $reComplete = $this->postJson("/api/auth/invitations/{$rawToken}/complete");
    $reComplete->assertStatus(400);

    // 5. Connexion avec les nouveaux identifiants et le TOTP
    $loginResponse = $this->postJson('/api/admin/auth/login', [
        'email' => 'nouveau.collaborateur@bango.app',
        'password' => 'BangoSecurePassword2026!',
    ]);
    $loginResponse->assertStatus(200);
});

it('allows direction to review access requests: list, approve to invite, and reject', function () {
    [$directionOp, $dirSession, $dirCsrf] = createTestAdminSession('direction');
    [$modoOp, $modoSession] = createTestAdminSession('moderation');

    // Création de 2 demandes d'accès
    $req1 = AdminAccessRequest::create([
        'first_name' => 'Paul',
        'last_name' => 'Direct',
        'email' => 'paul.direct@bango.app',
        'department' => 'Support',
        'requested_role' => 'support',
        'reason' => 'Besoin d\'accès ticket',
        'status' => 'pending',
    ]);

    $req2 = AdminAccessRequest::create([
        'first_name' => 'Marc',
        'last_name' => 'Finance',
        'email' => 'marc.fin@bango.app',
        'department' => 'Finance',
        'requested_role' => 'finance',
        'reason' => 'Comptabilité',
        'status' => 'pending',
    ]);

    // Un opérateur sans droit ne peut pas lister les demandes
    $forbidden = $this->withUnencryptedCookie('bango_admin_session', $modoSession)
        ->getJson('/api/admin/access-requests');
    $forbidden->assertStatus(403);

    // La direction peut lister les demandes
    $listResponse = $this->withUnencryptedCookie('bango_admin_session', $dirSession)
        ->getJson('/api/admin/access-requests');
    $listResponse->assertStatus(200)
        ->assertJsonPath('total', 2);

    // La direction approuve req1 -> génère une invitation
    $approveResponse = $this->withUnencryptedCookie('bango_admin_session', $dirSession)
        ->withHeader('X-CSRF-TOKEN', $dirCsrf)
        ->postJson("/api/admin/access-requests/{$req1->id}/approve");

    $approveResponse->assertStatus(200)
        ->assertJsonStructure(['access_request', 'invitation' => ['token', 'email']]);

    $req1->refresh();
    expect($req1->status)->toBe('approved')
        ->and($req1->reviewed_by)->toBe($directionOp->id);

    // La direction rejette req2
    $rejectResponse = $this->withUnencryptedCookie('bango_admin_session', $dirSession)
        ->withHeader('X-CSRF-TOKEN', $dirCsrf)
        ->postJson("/api/admin/access-requests/{$req2->id}/reject", [
            'reason' => 'Demande non justifiée pour le moment.',
        ]);

    $rejectResponse->assertStatus(200);
    $req2->refresh();
    expect($req2->status)->toBe('rejected');
});

/*
|--------------------------------------------------------------------------
| ISSUE 11 : Erreurs 500 sur PostgreSQL
|--------------------------------------------------------------------------
*/
it('prevents PostgreSQL 500 on avatar_url, country listings count, search, and UUID references', function () {
    [$directionOp, $sessionId, $csrfToken] = createTestAdminSession('direction');

    // 1. Modération queue et fiche avec avatar_url (pas avatar)
    $reporter = User::factory()->create(['avatar_url' => 'https://bango.test/reporter.jpg']);
    $reported = User::factory()->create(['avatar_url' => 'https://bango.test/reported.jpg']);
    $post = Post::factory()->create(['user_id' => $reported->id]);

    $report = Report::create([
        'reporter_id' => $reporter->id,
        'reported_user_id' => $reported->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::InappropriateContent->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $queueResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/queue');
    $queueResponse->assertStatus(200);

    $caseResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/moderation/cases/{$report->reference}");
    $caseResponse->assertStatus(200);

    // 2. Pays shell : listings count via owner (pas listings.country_id)
    $currency = Currency::create([
        'code' => 'XAF',
        'name' => 'Franc CFA',
        'symbol' => 'FCFA',
        'is_active' => true,
    ]);

    $country = Country::create([
        'name' => 'Cameroun',
        'code' => 'CM',
        'iso2' => 'CM',
        'iso3' => 'CMR',
        'phone_code' => '+237',
        'currency_id' => $currency->id,
        'is_active' => true,
    ]);

    $landlord = User::factory()->create(['country_id' => $country->id]);
    Listing::factory()->create(['owner_id' => $landlord->id]);

    $countriesResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/shell/countries');
    $countriesResponse->assertStatus(200);

    // 3. Recherche globale : transactions paiement sans colonne payment_method
    PaymentTransaction::create([
        'transaction_id' => 'TXN-999-TEST',
        'idempotency_key' => 'IDEMP-'.Str::random(16),
        'user_id' => $landlord->id,
        'service_type' => 'subscription',
        'amount' => 5000,
        'currency' => 'XAF',
        'channel' => 'mobile_money',
        'provider' => 'monetbil',
        'status' => 'confirmed',
    ]);

    $searchResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/search?q=monetbil');
    $searchResponse->assertStatus(200);

    // 4. Lookups sécurisés : passer une référence non-UUID ne déclenche PAS de syntaxe uuid invalide (500)
    $kycNonUuid = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/requests/REF-KYC-NON-EXISTENT');
    $kycNonUuid->assertStatus(404);

    $docNonUuid = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/kyc/requests/REF-KYC-NON-EXISTENT/documents/DOC-NON-UUID/open');
    $docNonUuid->assertStatus(404);

    $listingNonUuid = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/listings/LST-NON-EXISTENT');
    $listingNonUuid->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| ISSUE 12 : Notifications aux utilisateurs lors de chaque décision
|--------------------------------------------------------------------------
*/
it('sends notifications with reasons for KYC decisions, listing controls, and moderation actions', function () {
    Notification::fake();

    [$directionOp, $sessionId, $csrfToken] = createTestAdminSession('direction');

    // 1. Décision KYC -> notification envoyée au demandeur
    $kycUser = User::factory()->create();
    $submission = KycSubmission::create([
        'user_id' => $kycUser->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'full_name' => 'Demandeur Test',
        'id_type' => 'cni',
        'id_number' => 'CNI-1234',
    ]);

    $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'reject',
            'reason' => 'Document illisible',
        ])
        ->assertStatus(200);

    Notification::assertSentTo($kycUser, KycDecisionNotification::class, function ($n) {
        return $n->decision === 'reject' && str_contains((string) $n->reason, 'Document illisible');
    });

    // 2. Décision Annonce -> notification envoyée au propriétaire
    $owner = User::factory()->create();
    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Review,
    ]);

    $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/listings/{$listing->id}/review", [
            'decision' => 'reject',
            'reason' => 'Photos non conformes aux critères de qualité.',
            'notes' => 'Photos non conformes aux critères de qualité.',
        ])
        ->assertStatus(200);

    Notification::assertSentTo($owner, ListingDecisionNotification::class, function ($n) {
        return $n->decision === 'reject' && str_contains((string) $n->reason, 'Photos non conformes');
    });

    // 3. Modération warn_author -> notification d'avertissement
    $author = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $author->id]);
    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $author->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::InappropriateContent->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'warn_author',
            'justification' => 'Veuillez respecter les consignes de publication.',
        ])
        ->assertStatus(200);

    Notification::assertSentTo($author, ModerationDecisionNotification::class, function ($n) {
        return $n->action === 'warn_author';
    });
});

/*
|--------------------------------------------------------------------------
| ISSUE 13 : Le KYC ne donne PAS le badge sans abonnement
|--------------------------------------------------------------------------
*/
it('ensures KYC verification only sets is_verified and leaves badge to subscriptions', function () {
    [$directionOp, $sessionId, $csrfToken] = createTestAdminSession('verification');

    $userWithoutSub = User::factory()->create([
        'badge' => 'none',
        'is_verified' => false,
    ]);

    $submission = KycSubmission::create([
        'user_id' => $userWithoutSub->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'full_name' => 'Bailleur Sans Abonnement',
        'id_type' => 'cni',
        'id_number' => 'CNI-NO-SUB',
    ]);

    $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'approve',
            'notes' => 'Dossier approuvé.',
            'validity_months' => 12,
        ])
        ->assertStatus(200);

    $userWithoutSub->refresh();
    expect($userWithoutSub->is_verified)->toBeTrue()
        ->and($userWithoutSub->verification_lapsed)->toBeFalse()
        ->and($userWithoutSub->badge)->toBe('none'); // Le KYC ne donne PAS le badge sans abonnement !
});

/*
|--------------------------------------------------------------------------
| ISSUE 14 : L'application mobile consulte la décision KYC sans erreur max(uuid)
|--------------------------------------------------------------------------
*/
it('allows mobile app to view KYC status with latestOfMany(created_at) on UUID primary key', function () {
    $user = User::factory()->create(['is_verified' => true]);

    $sub = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Approved->value,
        'profile_category' => 'landlord',
        'full_name' => 'Bailleur App',
        'id_type' => 'cni',
        'id_number' => 'CNI-APP-1',
    ]);

    // Appel mobile authentifié avec actingAs
    $response = $this->actingAs($user, 'api')
        ->getJson('/api/landlord/kyc');

    $response->assertStatus(200)
        ->assertJsonPath('is_verified', true)
        ->assertJsonPath('latest_submission.id', $sub->id);
});

/*
|--------------------------------------------------------------------------
| ISSUE 15 : Actions de modération ciblées et garde-fous
|--------------------------------------------------------------------------
*/
it('enforces moderation target-specific guardrails, suspension duration, and operator restrictions', function () {
    [$modoOp, $sessionId, $csrfToken] = createTestAdminSession('moderation');

    // 1. 'remove' sur un signalement de compte utilisateur -> rejeté 422
    $targetUser = User::factory()->create();
    $userReport = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $targetUser->id,
        'reportable_type' => User::class,
        'reportable_id' => $targetUser->id,
        'reason' => ReportReason::InappropriateContent->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $removeUserResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$userReport->reference}/decision", [
            'action' => 'remove',
            'justification' => 'Suppression compte',
        ]);

    $removeUserResponse->assertStatus(422)
        ->assertJsonValidationErrors(['case']);

    expect(User::where('id', $targetUser->id)->exists())->toBeTrue();

    // 2. 'hide' sur une annonce -> écrit ListingStatus::Draft (pas 'suspended')
    $owner = User::factory()->create();
    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
    ]);

    $listingReport = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $owner->id,
        'reportable_type' => Listing::class,
        'reportable_id' => $listing->id,
        'reason' => ReportReason::InappropriateContent->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $hideResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$listingReport->reference}/decision", [
            'action' => 'hide',
            'justification' => 'Annonce non conforme',
        ]);

    $hideResponse->assertStatus(200);
    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Draft);

    // 3. 'suspend_author' fixe une durée (suspended_until), crée UserAccountSanction et ne supprime pas le compte
    $author = User::factory()->create();
    $authorPost = Post::factory()->create(['user_id' => $author->id]);
    $authorReport = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $author->id,
        'reportable_type' => Post::class,
        'reportable_id' => $authorPost->id,
        'reason' => ReportReason::InappropriateContent->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $suspendResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$authorReport->reference}/decision", [
            'action' => 'suspend_author',
            'duration_days' => 7,
            'justification' => 'Infraction répétée',
        ]);

    $suspendResponse->assertStatus(200);

    $author->refresh();
    expect($author->status)->toBe('suspended')
        ->and($author->suspended_until)->not->toBeNull()
        ->and($author->suspended_until->isFuture())->toBeTrue();

    $this->assertDatabaseHas('user_account_sanctions', [
        'user_id' => $author->id,
        'type' => 'suspension',
        'is_active' => true,
    ]);

    // 4. Assignation et escalade rejettent un compte utilisateur ordinaire sans rôle opérateur
    $ordinaryUser = User::factory()->create(); // pas de rôle opérateur

    $assignResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/moderation/cases/assign', [
            'case_refs' => [$listingReport->reference],
            'operator_id' => $ordinaryUser->id,
        ]);

    $assignResponse->assertStatus(422);

    $escalateResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$listingReport->reference}/escalate", [
            'senior_operator_id' => $ordinaryUser->id,
            'reason' => 'Escalade vers superviseur',
        ]);

    $escalateResponse->assertStatus(422);
});
