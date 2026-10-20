<?php

use App\Enums\Admin\AdminPermission;
use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminChallenge;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\Support\CopyrightClaim;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationDecision;
use App\Models\Support\Report;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Auth\DeviceVerificationCodeNotification;
use App\Notifications\Auth\UnknownDeviceReportedNotification;
use App\Services\Admin\AdminListingControlService;
use App\Services\Listing\ListingService;
use App\Services\Payment\PaymentEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();

    // 1. Roles
    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], [
        'name' => 'Admin',
        'permissions' => AdminPermission::all(),
    ]);
    $this->userRole = Role::firstOrCreate(['slug' => 'user'], [
        'name' => 'User',
        'permissions' => [],
    ]);

    // 2. Admin User and Session
    $this->admin = User::factory()->create(['role_id' => $this->adminRole->id]);

    $this->sessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    $this->adminSession = AdminSession::create([
        'id' => hash('sha256', $this->sessionId),
        'user_id' => $this->admin->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
        'allowed_countries' => null,
    ]);

    $this->withHeaders([
        'X-Bango-Admin-Session' => $this->sessionId,
        'X-CSRF-Token' => $this->csrfToken,
    ])->withUnencryptedCookies([
        'bango_admin_session' => $this->sessionId,
        'bango_admin_csrf' => $this->csrfToken,
    ]);
});

// =========================================================================
// POINT 16 : Recours rattachés à une vraie décision de sanction
// =========================================================================

test('point 16: appeal must point to a valid moderation decision affecting the user', function () {
    $offender = User::factory()->create([
        'role_id' => $this->userRole->id,
        'status' => 'suspended',
    ]);
    $otherUser = User::factory()->create(['role_id' => $this->userRole->id]);

    $report = Report::create([
        'reference' => 'REP-'.bin2hex(random_bytes(4)),
        'reporter_id' => $this->admin->id,
        'reported_user_id' => $offender->id,
        'reportable_type' => User::class,
        'reportable_id' => (string) $offender->id,
        'reason' => ReportReason::Harassment->value,
        'status' => ReportStatus::Resolved->value,
    ]);

    $decision = ModerationDecision::create([
        'report_id' => $report->id,
        'operator_id' => $this->admin->id,
        'action' => 'account_suspended',
        'justification' => 'Harcèlement répété',
    ]);

    // Offender appeals: succeeds
    Passport::actingAs($offender);
    $res = $this->postJson('/api/moderation/appeals', [
        'moderation_decision_id' => $decision->id,
        'reason' => 'Compte suspendu par erreur',
        'explanation' => 'Je conteste cette décision car mon compte a été usurpé.',
    ]);

    $res->assertStatus(201);
    expect(ModerationAppeal::where('moderation_decision_id', $decision->id)->exists())->toBeTrue();
    $appeal = ModerationAppeal::where('moderation_decision_id', $decision->id)->first();
    expect($appeal->user_id)->toBe($offender->id);
    expect($appeal->sanction_type)->toBe('account_suspension');

    // Another user tries to appeal the same decision: rejected 403
    Passport::actingAs($otherUser);
    $res2 = $this->postJson('/api/moderation/appeals', [
        'moderation_decision_id' => $decision->id,
        'reason' => 'Tentative non autorisée',
        'explanation' => 'Tentative de réactiver le compte d\'un autre utilisateur.',
    ]);
    $res2->assertStatus(403);
});

// =========================================================================
// POINT 17 : Verrou "retirée par BANGO" que seul le back-office peut lever
// =========================================================================

test('point 17: listing withdrawn by bango cannot be republished by owner until lifted by admin', function () {
    $owner = User::factory()->create(['role_id' => $this->userRole->id]);

    /** @var Listing $listing */
    $listing = Listing::create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Villa Moderne Cocody',
        'description' => 'Superbe villa avec piscine',
        'mode' => ListingMode::Rent->value,
        'kind' => ListingKind::Villa->value,
        'status' => ListingStatus::Published->value,
        'price' => 500000,
        'currency' => 'XAF',
        'city' => 'Abidjan',
    ]);

    $controlService = app(AdminListingControlService::class);
    $listingService = app(ListingService::class);

    // 1. Admin unpublishes the listing
    $controlService->unpublish($listing, $this->admin, 'Photos non conformes aux CGU BANGO');
    $listing->refresh();

    expect($listing->isWithdrawnByBango())->toBeTrue();
    expect($listing->status)->toBe(ListingStatus::Draft);

    // 2. Owner attempts to republish directly: blocked
    expect(fn () => $listingService->updateListingStatus($listing, $owner, ListingStatus::Published))
        ->toThrow(ValidationException::class);

    // 3. Admin lifts the withdrawal
    $controlService->liftBangoWithdrawal($listing, $this->admin, 'Contrôle effectué, régularisation validée');
    $listing->refresh();

    expect($listing->isWithdrawnByBango())->toBeFalse();

    // 4. Now owner can publish directly
    $updated = $listingService->updateListingStatus($listing, $owner, ListingStatus::Published);
    expect($updated->status)->toBe(ListingStatus::Published);
});

// =========================================================================
// POINT 18 : Fusion même propriétaire et remboursement des visites confirmées
// =========================================================================

test('point 18: duplicate merge is restricted to same owner and refunds confirmed visits', function () {
    $ownerA = User::factory()->create(['role_id' => $this->userRole->id]);
    $ownerB = User::factory()->create(['role_id' => $this->userRole->id]);
    $visitor = User::factory()->create(['role_id' => $this->userRole->id]);

    $primary = Listing::create([
        'owner_id' => $ownerA->id,
        'created_by_id' => $ownerA->id,
        'title' => 'Appartement Plateau Principal',
        'description' => 'Appartement standing',
        'mode' => ListingMode::Rent->value,
        'kind' => ListingKind::Flat->value,
        'status' => ListingStatus::Published->value,
        'price' => 300000,
        'currency' => 'XAF',
        'city' => 'Abidjan',
    ]);

    $secondaryDifferentOwner = Listing::create([
        'owner_id' => $ownerB->id,
        'created_by_id' => $ownerB->id,
        'title' => 'Appartement Plateau Doublon Différent',
        'description' => 'Doublon frauduleux',
        'mode' => ListingMode::Rent->value,
        'kind' => ListingKind::Flat->value,
        'status' => ListingStatus::Published->value,
        'price' => 300000,
        'currency' => 'XAF',
        'city' => 'Abidjan',
    ]);

    $controlService = app(AdminListingControlService::class);

    // 1. Merge between different owners must throw ValidationException
    expect(fn () => $controlService->mergeDuplicates((string) $primary->id, (string) $secondaryDifferentOwner->id, $this->admin))
        ->toThrow(ValidationException::class);

    // 2. Secondary listing with SAME owner
    $secondarySameOwner = Listing::create([
        'owner_id' => $ownerA->id,
        'created_by_id' => $ownerA->id,
        'title' => 'Appartement Plateau Doublon Même Propriétaire',
        'description' => 'Doublon accidentel',
        'mode' => ListingMode::Rent->value,
        'kind' => ListingKind::Flat->value,
        'status' => ListingStatus::Published->value,
        'price' => 300000,
        'currency' => 'XAF',
        'city' => 'Abidjan',
    ]);

    // Create payment transaction for a confirmed visit on secondary listing
    $tx = PaymentTransaction::create([
        'user_id' => $visitor->id,
        'service_type' => 'visit',
        'amount' => 5000,
        'fee_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 5000,
        'credit_used' => 0,
        'amount_charged' => 5000,
        'currency' => 'XAF',
        'channel' => 'mobile_money',
        'status' => PaymentStatus::Confirmed->value,
        'provider' => PaymentProvider::Monetbil->value,
        'reference' => 'PAY-VISIT-'.bin2hex(random_bytes(4)),
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $secondarySameOwner->id,
        'owner_id' => $ownerA->id,
        'start_at' => now()->addDays(2),
        'end_at' => now()->addDays(2)->addHour(),
        'capacity' => 1,
        'status' => 'open',
    ]);

    $visit = VisitReservation::create([
        'listing_id' => $secondarySameOwner->id,
        'slot_id' => $slot->id,
        'owner_id' => $ownerA->id,
        'prospect_id' => $visitor->id,
        'status' => VisitReservationStatus::Confirmed->value,
        'transaction_id' => $tx->id,
        'fee' => 5000,
        'currency' => 'XAF',
    ]);

    // Merge same owner succeeds
    $result = $controlService->mergeDuplicates((string) $primary->id, (string) $secondarySameOwner->id, $this->admin);
    expect($result->id)->toBe($primary->id);

    // Secondary listing is draft and locked
    $secondarySameOwner->refresh();
    expect($secondarySameOwner->isWithdrawnByBango())->toBeTrue();

    // Visit is refunded and transaction is refunded
    $visit->refresh();
    expect($visit->status)->toBe(VisitReservationStatus::Refunded);
    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Refunded);
});

// =========================================================================
// POINT 19 : Modules vides par construction
// =========================================================================

test('point 19: copyright claims endpoint works and role change submits to 4-eyes approval', function () {
    // 1. Copyright claim public reporting endpoint
    $claimant = User::factory()->create(['role_id' => $this->userRole->id]);
    $this->actingAs($claimant);

    $claimRes = $this->postJson('/api/legal/copyright-claims', [
        'claimant_name' => 'Alice Photographe',
        'claimant_email' => 'alice@example.com',
        'content_type' => 'post',
        'content_id' => '123',
        'work_title' => 'Mes photographies d\'architecture',
        'reason' => 'Mes clichés ont été reproduits sans autorisation légale.',
        'proof_url' => 'https://bango.test/listings/123/photo.jpg',
    ]);

    $claimRes->assertStatus(201);
    expect(CopyrightClaim::where('claimant_email', 'alice@example.com')->exists())->toBeTrue();

    // 2. Sensitive operator role change submits to 4-eyes approval
    $this->withHeaders([
        'X-Bango-Admin-Session' => $this->sessionId,
        'X-CSRF-Token' => $this->csrfToken,
    ]);

    $targetUser = User::factory()->create(['role_id' => $this->userRole->id]);
    $moderationRole = Role::where('slug', 'moderation')->first();

    $roleRes = $this->patchJson("/api/admin/users/{$targetUser->id}/role", [
        'role_id' => $moderationRole->id,
        'reason' => 'Promotion au pôle modération',
    ]);

    $roleRes->assertStatus(202);
    $roleRes->assertJsonFragment(['requires_approval' => true]);
});

// =========================================================================
// POINT 20 : Rôles, permissions et filtrage par pays
// =========================================================================

test('point 20: direction can update role permissions and operator session filters by allowed_countries', function () {
    // 1. Update role permissions
    $supportRole = Role::where('slug', 'support')->first();

    $res = $this->putJson("/api/admin/roles/{$supportRole->id}", [
        'permissions' => ['support:view', 'support:tickets:respond'],
    ]);

    $res->assertStatus(200);
    $supportRole->refresh();
    expect($supportRole->permissions)->toBe(['support:view', 'support:tickets:respond']);

    // 2. Country filtering in listing control
    $currency = Currency::firstOrCreate(['code' => 'XOF'], ['name' => 'Franc CFA', 'symbol' => 'CFA', 'is_active' => true]);

    $countryCI = Country::firstOrCreate(['code' => 'CI'], [
        'name' => 'Côte d\'Ivoire',
        'iso2' => 'CI',
        'iso3' => 'CIV',
        'phone_code' => '225',
        'currency_id' => $currency->id,
    ]);
    $countrySN = Country::firstOrCreate(['code' => 'SN'], [
        'name' => 'Sénégal',
        'iso2' => 'SN',
        'iso3' => 'SEN',
        'phone_code' => '221',
        'currency_id' => $currency->id,
    ]);

    $userCI = User::factory()->create(['country_code' => 'CI', 'country_id' => $countryCI->id]);
    $userSN = User::factory()->create(['country_code' => 'SN', 'country_id' => $countrySN->id]);

    Listing::create([
        'owner_id' => $userCI->id,
        'created_by_id' => $userCI->id,
        'title' => 'Maison Abidjan',
        'mode' => ListingMode::Rent->value,
        'kind' => ListingKind::Villa->value,
        'status' => ListingStatus::Published->value,
        'price' => 200000,
        'currency' => 'XAF',
        'city' => 'Abidjan',
    ]);

    Listing::create([
        'owner_id' => $userSN->id,
        'created_by_id' => $userSN->id,
        'title' => 'Maison Dakar',
        'mode' => ListingMode::Rent->value,
        'kind' => ListingKind::Villa->value,
        'status' => ListingStatus::Published->value,
        'price' => 200000,
        'currency' => 'XAF',
        'city' => 'Dakar',
    ]);

    // Restrict operator session to 'CI'
    $this->adminSession->update(['allowed_countries' => ['CI']]);

    $res = $this->getJson('/api/admin/listings');
    $res->assertStatus(200);
    expect($res->json('meta.total'))->toBe(1);
    expect($res->json('data.0.title'))->toBe('Maison Abidjan');
});

// =========================================================================
// POINT 21 : Empreinte chaînée et immuabilité de l'audit
// =========================================================================

test('point 21: audit log hashes are chained, immutable, and log sensitive operations', function () {
    // 1. Verify chaining
    $log1 = AdminAuditLog::logChainedEvent('test.event1', $this->admin, 'User', '1', 'User 1');
    $log2 = AdminAuditLog::logChainedEvent('test.event2', $this->admin, 'User', '2', 'User 2');

    expect($log2->prev_hash)->toBe($log1->hash);
    expect($log1->verifyHash())->toBeTrue();
    expect($log2->verifyHash())->toBeTrue();

    // 2. Verify immutability
    expect(function () use ($log1) {
        $log1->update(['event' => 'tampered.event']);
    })->toThrow(DomainException::class);

    expect(function () use ($log1) {
        $log1->delete();
    })->toThrow(DomainException::class);

    // 3. Sensitive operation: refund logs to chained audit
    $user = User::factory()->create();
    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => 'subscription',
        'amount' => 15000,
        'fee_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 15000,
        'credit_used' => 0,
        'amount_charged' => 15000,
        'currency' => 'XAF',
        'channel' => 'mobile_money',
        'status' => PaymentStatus::Confirmed->value,
        'provider' => PaymentProvider::Monetbil->value,
        'reference' => 'PAY-SUB-'.bin2hex(random_bytes(4)),
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $paymentEngine = app(PaymentEngineService::class);
    $paymentEngine->refundTransaction($tx, $this->admin, 'Geste commercial client');

    $refundLog = AdminAuditLog::where('event', 'finance.refund_processed')->latest('id')->first();
    expect($refundLog)->not->toBeNull();
    expect($refundLog->verifyHash())->toBeTrue();
});

// =========================================================================
// POINT 22 : Appareil inconnu et verrouillage de sécurité
// =========================================================================

test('point 22: device resend sends real notification and report locks the account', function () {
    Notification::fake();

    $operator = User::factory()->create(['role_id' => $this->adminRole->id]);

    $challenge = AdminChallenge::create([
        'user_id' => $operator->id,
        'token' => bin2hex(random_bytes(32)),
        'type' => 'totp',
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
    ]);

    // 1. Resend sends real notification with 6-digit code
    $resendRes = $this->postJson('/api/auth/device/resend', [
        'challenge_token' => $challenge->token,
    ]);

    $resendRes->assertStatus(200);
    Notification::assertSentTo($operator, DeviceVerificationCodeNotification::class);

    // 2. Report locks the account, revokes sessions & challenges
    $reportRes = $this->postJson('/api/auth/device/report', [
        'challenge_token' => $challenge->token,
        'reason' => 'Connexion suspecte non autorisée depuis un nouvel appareil',
    ]);

    $reportRes->assertStatus(200);
    $reportRes->assertJsonFragment(['account_locked' => true]);

    $operator->refresh();
    expect($operator->isLocked())->toBeTrue();
    expect($operator->locked_until)->not->toBeNull();

    $challenge->refresh();
    expect($challenge->isValid())->toBeFalse();

    Notification::assertSentTo($operator, UnknownDeviceReportedNotification::class);
});
