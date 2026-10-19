<?php

namespace Tests\Feature\Admin\Direction;

use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Models\Admin\AdminAuditExport;
use App\Models\Admin\AdminSession;
use App\Models\Admin\ScheduledReport;
use App\Models\Admin\SystemConfiguration;
use App\Models\Auth\Role;
use App\Models\Content\Interest;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\Geo\Operator;
use App\Models\Legal\LegalDocument;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\PricingChangeProposal;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createDirectionSessionForStats(): array
{
    $role = Role::where('slug', 'direction')->firstOrFail();
    $operator = User::where('email', 'ousmane.ba@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => 'op.direction.stats.'.uniqid().'@bango.app',
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

/* ==========================================================================
 * SECTION 5 : PARAMÈTRES & CONFIGURATION SYSTÈME
 * ========================================================================== */

it('consults pricing catalog, previews change impact, and submits scheduled pricing change', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    $product = SubscriptionProduct::create([
        'code' => 'pro_immo',
        'name' => 'Pro Immobilière',
        'badge_type' => 'pro',
        'target_role' => 'agent',
        'is_active' => true,
        'display_order' => 1,
    ]);

    $price = SubscriptionPrice::create([
        'product_id' => $product->id,
        'cadence' => 'monthly',
        'price_amount' => 25000,
        'currency' => 'XAF',
        'duration_days' => 30,
        'is_active' => true,
    ]);

    // 1. Get pricing catalog
    $catalogResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/settings/pricing');

    $catalogResponse->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);

    // 2. Preview impact of pricing change
    $impactResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/settings/pricing/impact?price_id={$price->id}&proposed_amount=30000");

    $impactResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'current_price' => 25000,
                'simulated_new_price' => 30000,
            ],
        ]);

    // 3. Propose scheduled pricing change
    $proposalResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/settings/pricing/changes', [
            'price_id' => $price->id,
            'new_price_amount' => 30000,
            'notice_days' => 15,
            'reason' => 'Ajustement inflation et indexation annuelle des grilles tarifaires.',
        ]);

    $proposalResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'new_price_amount' => 30000,
                'status' => 'scheduled',
            ],
        ]);

    expect(PricingChangeProposal::where('subscription_price_id', $price->id)->count())->toBe(1);
});

it('consults and updates automated moderation thresholds in system_configurations', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    // 1. Consult thresholds
    $getThresholds = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/settings/thresholds');

    $getThresholds->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);

    // 2. Update thresholds
    $putThresholds = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->putJson('/api/admin/settings/thresholds', [
            'auto_hide_report_count' => 7,
            'auto_flag_severity_threshold' => 85,
            'sla_moderation_hours' => 12,
            'fraud_risk_score_threshold' => 75,
        ]);

    $putThresholds->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'auto_hide_report_count' => 7,
                'auto_flag_severity_threshold' => 85,
                'sla_moderation_hours' => 12,
                'fraud_risk_score_threshold' => 75,
            ],
        ]);

    expect(SystemConfiguration::getValue('thresholds.auto_hide_report_count'))->toBe('7');
});

it('suspends telecom operator with fallback and resumes successfully', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    $currency = Currency::firstOrCreate(
        ['code' => 'XAF'],
        ['name' => 'Franc CFA', 'symbol' => 'FCFA']
    );

    $country = Country::firstOrCreate(
        ['code' => 'CM'],
        ['name' => 'Cameroun', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id]
    );

    $fallback = Operator::create([
        'country_id' => $country->id,
        'code' => 'MTN_CM_'.uniqid(),
        'name' => 'MTN MoMo Cameroun',
        'is_active' => true,
    ]);

    $targetOp = Operator::create([
        'country_id' => $country->id,
        'code' => 'ORANGE_CM_'.uniqid(),
        'name' => 'Orange Money Cameroun',
        'is_active' => true,
    ]);

    // 1. Preview suspension impact
    $impactResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/settings/operators/impact?operator_id={$targetOp->id}&fallback_operator_id={$fallback->id}");

    $impactResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'operator' => ['id' => $targetOp->id],
                'has_automatic_fallback' => true,
            ],
        ]);

    // 2. Suspend operator
    $suspendResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/settings/operators/{$targetOp->id}/suspend", [
            'reason' => 'Panne passerelle réseau chez le carrier Orange Money.',
            'fallback_operator_id' => $fallback->id,
        ]);

    $suspendResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_active' => false,
                'fallback_operator_id' => $fallback->id,
            ],
        ]);

    expect($targetOp->fresh()->is_active)->toBeFalse();

    // 3. Resume operator
    $resumeResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/settings/operators/{$targetOp->id}/resume");

    $resumeResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_active' => true,
            ],
        ]);

    expect($targetOp->fresh()->is_active)->toBeTrue();
});

it('manages geographic reference (cities, areas create, update, ignore)', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    $currencyCi = Currency::firstOrCreate(
        ['code' => 'XOF'],
        ['name' => 'Franc CFA UEMOA', 'symbol' => 'FCFA']
    );

    $country = Country::firstOrCreate(
        ['code' => 'CI'],
        ['name' => 'Côte d\'Ivoire', 'iso2' => 'CI', 'iso3' => 'CIV', 'currency_id' => $currencyCi->id]
    );

    $city = City::create([
        'country_id' => $country->id,
        'name' => 'Abidjan',
        'slug' => 'abidjan',
        'is_active' => true,
    ]);

    // 1. List cities
    $citiesResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/settings/cities');

    $citiesResponse->assertStatus(200)->assertJsonStructure(['success', 'data']);

    // 2. Add an area (neighbourhood) to city
    $createAreaResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/settings/areas/{$city->name}", [
            'name' => 'Cocody Riviera Golf',
        ]);

    $createAreaResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Cocody Riviera Golf',
                'city_id' => $city->id,
            ],
        ]);

    $neighbourhoodId = $createAreaResponse->json('data.id');

    // 3. Update area name
    $updateAreaResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->patchJson("/api/admin/settings/areas/{$city->name}/Cocody Riviera Golf", [
            'name' => 'Cocody Riviera Golf Élite',
        ]);

    $updateAreaResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Cocody Riviera Golf Élite',
            ],
        ]);

    // 4. Mark area as ignored (alias POST /ignore)
    $ignoreResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/settings/areas/ignore', [
            'city' => $city->name,
            'name' => 'Cocody Riviera Golf Élite',
            'ignore' => true,
        ]);

    $ignoreResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_ignored' => true,
            ],
        ]);

    expect(Neighbourhood::find($neighbourhoodId)->is_ignored)->toBeTrue();
});

it('manages interests (list, create, update)', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    // 1. List interests
    $listResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/settings/interests');

    $listResponse->assertStatus(200)->assertJsonStructure(['success', 'data']);

    // 2. Create interest
    $createResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/settings/interests', [
            'id' => 'immo_prestige',
            'name_fr' => 'Immobilier de Prestige',
            'name_en' => 'Luxury Real Estate',
        ]);

    $createResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'name_fr' => 'Immobilier de Prestige',
            ],
        ]);

    $interestId = $createResponse->json('data.id');

    // 3. Update interest
    $updateResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->patchJson("/api/admin/settings/interests/{$interestId}", [
            'name_fr' => 'Immobilier d\'Exception & Luxe',
        ]);

    $updateResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'name_fr' => 'Immobilier d\'Exception & Luxe',
            ],
        ]);
});

it('publishes a new version of legal documents (CGU)', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    $doc = LegalDocument::create([
        'slug' => 'cgu_general',
        'title' => 'Conditions Générales d\'Utilisation BANGO',
        'version' => '1.0.0',
        'locale' => 'fr',
        'content_markdown' => 'Version 1.0 initiale des conditions générales...',
        'effective_date' => now()->subMonths(6),
        'published_at' => now()->subMonths(6),
        'is_published' => true,
        'is_mandatory' => true,
    ]);

    $publishResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/settings/legal/cgu_general/publish', [
            'version' => '2.0.0',
            'title' => 'Conditions Générales d\'Utilisation BANGO v2',
            'content_markdown' => 'Version 2.0 révisée des CGU BANGO incluant les règles de vérification d\'identité...',
            'is_mandatory' => true,
        ]);

    $publishResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'version' => '2.0.0',
                'is_published' => true,
            ],
        ]);

    expect(LegalDocument::where('slug', 'cgu_general')->where('version', '2.0.0')->exists())->toBeTrue();
});

it('controls service maintenance mode, minimal version and incident banner', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    // 1. Consult service status
    $statusResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/settings/service');

    $statusResponse->assertStatus(200)->assertJsonStructure(['success', 'data']);

    // 2. Enable maintenance mode
    $enableMaint = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/settings/service/actions', [
            'action' => 'toggle_maintenance',
            'params' => [
                'enabled' => true,
                'message' => 'Opération de migration d\'urgence sur cluster PostgreSQL.',
            ],
        ]);

    $enableMaint->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'maintenance_mode' => true,
            ],
        ]);

    // 3. Disable maintenance mode
    $disableMaint = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/settings/service/actions', [
            'action' => 'toggle_maintenance',
            'params' => [
                'enabled' => false,
                'message' => 'Migration terminée avec succès, service rétabli.',
            ],
        ]);

    $disableMaint->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'maintenance_mode' => false,
            ],
        ]);

    // 4. Consult delays reference
    $delaysResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/settings/delays');

    $delaysResponse->assertStatus(200)->assertJsonStructure(['success', 'data']);
});

/* ==========================================================================
 * SECTION 6 : STATISTIQUES STRATÉGIQUES & PILOTAGE
 * ========================================================================== */

it('provides real calculated strategic statistics (overview, acquisition, revenue, moderation, retention, immo)', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    // Populate some transactions
    $customer = User::factory()->create();
    PaymentTransaction::factory()->create([
        'user_id' => $customer->id,
        'amount' => 50000,
        'fee_amount' => 500,
        'currency' => 'XAF',
        'status' => PaymentStatus::Confirmed,
        'provider' => PaymentProvider::Monetbil,
    ]);

    // 1. Overview
    $overview = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/stats/overview');

    $overview->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'financial' => ['gross_revenue', 'net_revenue'],
                'subscriptions_and_badges',
                'credits_wallet',
                'community' => ['total_accounts', 'new_signups'],
            ],
        ]);

    // 2. Acquisition Funnel
    $acq = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/stats/acquisition');

    $acq->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'period' => ['from', 'to'],
                'funnel_steps' => [
                    '*' => ['step', 'label', 'count', 'conversion_rate_from_start'],
                ],
            ],
        ]);

    // 3. Revenue Breakdown
    $rev = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/stats/revenue');

    $rev->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'period' => ['from', 'to'],
                'financial_summary' => ['gross_revenue', 'refunds', 'operator_fees', 'net_revenue'],
                'by_service_type',
                'by_currency',
            ],
        ]);

    // 4. Moderation KPIs
    $mod = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/stats/moderation');

    $mod->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'reports_total',
                'median_resolution_time_hours',
                'appeal_rate_percentage',
            ],
        ]);

    // 5. Retention & Cohorts
    $ret = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/stats/retention');

    $ret->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'cohorts',
                'retention_definition',
            ],
        ]);

    // 6. Real Estate Performance
    $immo = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/stats/immo');

    $immo->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'rented_properties_count',
                'total_active_listings',
                'visits_kpis',
                'landlord_payouts_kpis',
            ],
        ]);
});

it('consults and updates scheduled periodic reports', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    // Ensure a scheduled report exists
    $report = ScheduledReport::create([
        'name' => 'Rapport Hebdomadaire Exécutif',
        'cadence' => 'weekly',
        'recipients' => ['ousmane.ba@bango.app'],
        'metrics' => ['financial', 'community'],
        'format' => 'csv',
        'is_active' => true,
    ]);

    // 1. List scheduled reports
    $reports = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/stats/reports');

    $reports->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'cadence', 'recipients', 'format'],
            ],
        ]);

    // 2. Update scheduled report
    $updateReport = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->putJson("/api/admin/stats/reports/{$report->id}", [
            'name' => 'Rapport Mensuel Stratégique',
            'cadence' => 'monthly',
            'recipients' => ['ousmane.ba@bango.app', 'finance.lead@bango.app'],
            'metrics' => ['financial', 'community'],
            'format' => 'csv',
            'is_active' => true,
        ]);

    $updateReport->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $report->id,
                'cadence' => 'monthly',
                'format' => 'csv',
            ],
        ]);
});

it('previews and downloads sanitized stats export', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    // 1. Preview stats export
    $previewResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/stats/exports/preview', [
            'domain' => 'overview',
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
        ]);

    $previewResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'domain' => 'overview',
            ],
        ]);

    // 2. Download stats CSV
    $downloadResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->post('/api/admin/stats/exports', [
            'domain' => 'overview',
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
        ]);

    $downloadResponse->assertStatus(200);
    expect($downloadResponse->headers->get('content-type'))->toContain('text/csv');
});

/* ==========================================================================
 * SECTION 7 : TABLEAU DE BORD GLOBAL & ONBOARDING DIRECTION
 * ========================================================================== */

it('delivers executive dashboard, exports it to CSV, inspects exports and checks onboarding status', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSessionForStats();

    // 1. Get Executive Dashboard
    $dashboardResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/dashboard');

    $dashboardResponse->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'operator' => ['id', 'name', 'email', 'role'],
                'kpis',
                'system_health',
                'governance',
            ],
        ]);

    // 2. Export Executive Dashboard CSV
    $exportDashboardResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->post('/api/admin/dashboard/exports');

    $exportDashboardResponse->assertStatus(200);
    expect($exportDashboardResponse->headers->get('content-type'))->toContain('text/csv');

    // 3. Consult details of an export
    $export = AdminAuditExport::create([
        'filename' => 'bango_test_export.csv',
        'file_path' => 'exports/bango_test_export.csv',
        'records_count' => 50,
        'sha256_seal' => hash('sha256', 'sample_content'),
        'requester_id' => $operator->id,
        'filters' => [],
        'status' => 'completed',
        'expires_at' => now()->addDays(7),
    ]);

    $exportDetailsResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/exports/{$export->id}");

    $exportDetailsResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $export->id,
                'filename' => 'bango_test_export.csv',
                'status' => 'completed',
            ],
        ]);

    // 4. Operator onboarding status
    $onboardingResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/me/onboarding');

    $onboardingResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'email' => $operator->email,
                'role_slug' => 'direction',
                'is_direction' => true,
                'onboarding_completed' => true,
            ],
        ]);
});
