<?php

namespace Tests\Feature\Subscription;

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Legal\LegalDocument;
use App\Models\Subscription\PricingChangeProposal;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Notifications\Subscription\PricingChangeScheduledNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    // Create direction operator session
    $role = Role::where('slug', 'direction')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'op.direction.'.uniqid().'@bango.app',
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

    // Create product and price
    $this->product = SubscriptionProduct::create([
        'code' => 'agent',
        'name' => 'Agent Immobilier Certifié',
        'badge_type' => 'agent',
        'target_role' => 'agent',
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->price = SubscriptionPrice::create([
        'product_id' => $this->product->id,
        'cadence' => 'monthly',
        'price_amount' => 10000,
        'currency' => 'XAF',
        'duration_days' => 30,
        'is_active' => true,
    ]);

    // Create initial legal document (CGU v1.0.0)
    $this->termsV1 = LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '1.0.0',
        'locale' => 'fr',
        'title' => 'CGU v1.0.0',
        'content_markdown' => 'Conditions Générales v1',
        'effective_date' => now()->subMonths(6),
        'is_published' => true,
        'is_mandatory' => true,
    ]);

    // Create upcoming legal document for price change (CGU v2.0.0)
    $this->termsV2 = LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '2.0.0',
        'locale' => 'fr',
        'title' => 'CGU v2.0.0 Grille Tarifaire Révisée',
        'content_markdown' => 'Conditions Générales v2 avec nouvelle grille tarifaire',
        'effective_date' => now()->addDays(30),
        'is_published' => false,
        'is_mandatory' => false,
    ]);
});

test('programming a pricing change links proposal to legal document version and notifies active subscribers', function () {
    Notification::fake();

    // Create active subscriber
    $subscriber = User::factory()->create([
        'name' => 'Jean Abonné',
        'accepted_terms_version' => '1.0.0',
        'terms_accepted_at' => now()->subMonths(2),
    ]);

    $subscription = Subscription::create([
        'user_id' => $subscriber->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'state' => 'active',
        'amount' => 10000,
        'currency' => 'XAF',
        'current_period_start' => now()->subDays(10),
        'current_period_end' => now()->addDays(20),
        'auto_renew' => true,
    ]);

    // Propose pricing change from 10 000 to 15 000 with 30 days notice
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->withHeader('X-CSRF-TOKEN', $this->csrfToken)
        ->postJson('/api/admin/settings/pricing/changes', [
            'price_id' => $this->price->id,
            'new_price_amount' => 15000,
            'notice_days' => 30,
            'reason' => 'Indexation annuelle et amélioration de la couverture du service.',
            'legal_document_id' => $this->termsV2->id,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.new_price_amount', '15000.00')
        ->assertJsonPath('data.legal_document_id', $this->termsV2->id)
        ->assertJsonPath('data.terms_version', '2.0.0');

    // Verify proposal saved in DB
    $proposal = PricingChangeProposal::where('price_id', $this->price->id)->firstOrFail();
    expect($proposal->terms_version)->toBe('2.0.0');
    expect($proposal->legal_document_id)->toBe($this->termsV2->id);

    // Verify subscriber received the notification with date and new amount
    Notification::assertSentTo($subscriber, PricingChangeScheduledNotification::class, function ($notification) {
        $mail = $notification->toMail($notification->proposal);
        $array = $notification->toArray($notification->proposal);

        return (float) $notification->proposal->new_price_amount === 15000.0
            && $notification->proposal->terms_version === '2.0.0'
            && $array['new_price'] === 15000.0
            && $array['terms_version'] === '2.0.0';
    });
});

test('daily command applies due proposals, updates catalog price, and leaves current subscribers on old price until renewal', function () {
    // Subscriber with active subscription at 10 000 XAF
    $subscriber = User::factory()->create([
        'accepted_terms_version' => '1.0.0',
        'terms_accepted_at' => now()->subMonths(1),
    ]);

    $subscription = Subscription::create([
        'user_id' => $subscriber->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'state' => 'active',
        'amount' => 10000,
        'currency' => 'XAF',
        'current_period_start' => now()->subDays(15),
        'current_period_end' => now()->addDays(15),
        'auto_renew' => true,
    ]);

    // Create a proposal that reached its effective date yesterday
    $dueProposal = PricingChangeProposal::create([
        'price_id' => $this->price->id,
        'subscription_price_id' => $this->price->id,
        'proposed_by_id' => $this->operator->id,
        'new_price_amount' => 15000,
        'notice_days' => 30,
        'effective_date' => Carbon::now()->subDay(),
        'status' => 'scheduled',
        'reason' => 'Échéance dépassée',
        'legal_document_id' => $this->termsV2->id,
        'terms_version' => '2.0.0',
    ]);

    // Create another proposal still in future notice period
    $futureProposal = PricingChangeProposal::create([
        'price_id' => $this->price->id,
        'subscription_price_id' => $this->price->id,
        'proposed_by_id' => $this->operator->id,
        'new_price_amount' => 18000,
        'notice_days' => 60,
        'effective_date' => Carbon::now()->addDays(20),
        'status' => 'scheduled',
        'reason' => 'Future échéance',
        'terms_version' => '3.0.0',
    ]);

    // Run daily command
    $exitCode = Artisan::call('pricing:apply-scheduled');
    expect($exitCode)->toBe(0);

    // 1. Due proposal is applied
    $dueProposal->refresh();
    expect($dueProposal->status)->toBe('applied')
        ->and($dueProposal->applied_at)->not->toBeNull();

    // 2. Future proposal remains scheduled
    $futureProposal->refresh();
    expect($futureProposal->status)->toBe('scheduled');

    // 3. Catalog price is now updated to 15 000 and requires terms v2.0.0
    $this->price->refresh();
    expect((float) $this->price->price_amount)->toBe(15000.0)
        ->and($this->price->required_terms_version)->toBe('2.0.0')
        ->and($this->price->legal_document_id)->toBe($this->termsV2->id);

    // 4. Linked CGU document is now published and mandatory
    $this->termsV2->refresh();
    expect($this->termsV2->is_published)->toBeTrue()
        ->and($this->termsV2->is_mandatory)->toBeTrue();

    // 5. Existing subscriber keeps their old amount (10 000 XAF) until renewal
    $subscription->refresh();
    expect((float) $subscription->amount)->toBe(10000.0)
        ->and($subscription->state)->toBe('active');
});

test('renewal and subscription at new price are blocked until terms version is accepted', function () {
    // Apply price change to 15 000 with required terms 2.0.0
    $this->price->update([
        'price_amount' => 15000,
        'required_terms_version' => '2.0.0',
        'legal_document_id' => $this->termsV2->id,
    ]);

    $this->termsV2->update([
        'is_published' => true,
        'is_mandatory' => true,
    ]);

    // Existing subscriber who only accepted terms v1.0.0
    $subscriber = User::factory()->create([
        'accepted_terms_version' => '1.0.0',
        'terms_accepted_at' => now()->subMonths(1),
    ]);

    $subscription = Subscription::create([
        'user_id' => $subscriber->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'state' => 'active',
        'amount' => 10000, // kept old amount
        'currency' => 'XAF',
        'current_period_start' => now()->subDays(29),
        'current_period_end' => now()->addDay(),
        'auto_renew' => true,
    ]);

    Passport::actingAs($subscriber);

    // 1. Check renewal info indicates terms acceptance is required
    $infoResponse = $this->getJson("/api/subscriptions/{$subscription->id}/renewal");
    $infoResponse->assertOk()
        ->assertJsonPath('data.terms_acceptance_required', true)
        ->assertJsonPath('data.required_terms_version', '2.0.0')
        ->assertJsonPath('data.renewal_price.amount', 15000);

    // 2. Renewal quote is BLOCKED with 403 TERMS_ACCEPTANCE_REQUIRED
    $quoteResponse = $this->postJson("/api/subscriptions/{$subscription->id}/renewal/quote", [
        'cadence' => 'monthly',
    ]);
    $quoteResponse->assertStatus(403)
        ->assertJsonPath('error', 'TERMS_ACCEPTANCE_REQUIRED')
        ->assertJsonPath('required_version', '2.0.0');

    // 3. Renewal confirmation is BLOCKED with 403 TERMS_ACCEPTANCE_REQUIRED
    $confirmResponse = $this->postJson("/api/subscriptions/{$subscription->id}/renewal/confirm", [
        'operator' => 'MTN',
        'phone' => '670000001',
        'cadence' => 'monthly',
    ]);
    $confirmResponse->assertStatus(403)
        ->assertJsonPath('error', 'TERMS_ACCEPTANCE_REQUIRED')
        ->assertJsonPath('required_version', '2.0.0');

    // 4. A new user trying to subscribe is also BLOCKED
    $newUser = User::factory()->create([
        'accepted_terms_version' => null,
        'terms_accepted_at' => null,
    ]);
    Passport::actingAs($newUser);

    $subscribeResponse = $this->postJson('/api/subscriptions/subscribe', [
        'price_id' => $this->price->id,
    ]);
    $subscribeResponse->assertStatus(403)
        ->assertJsonPath('error', 'TERMS_ACCEPTANCE_REQUIRED')
        ->assertJsonPath('required_version', '2.0.0');

    // 5. User accepts the new terms version v2.0.0
    Passport::actingAs($subscriber);
    $acceptResponse = $this->postJson('/api/legal/accept-terms', [
        'version' => '2.0.0',
    ]);
    $acceptResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('version', '2.0.0');

    // 6. Renewal quote now SUCCEEDS at the new price 15 000
    $allowedQuote = $this->postJson("/api/subscriptions/{$subscription->id}/renewal/quote", [
        'cadence' => 'monthly',
    ]);
    $allowedQuote->assertOk()
        ->assertJsonPath('data.price.amount', 15000);

    // 7. New user accepts terms and can subscribe
    Passport::actingAs($newUser);
    $this->postJson('/api/legal/accept-terms', ['version' => '2.0.0'])->assertOk();

    $allowedSubscribe = $this->postJson('/api/subscriptions/subscribe', [
        'price_id' => $this->price->id,
    ]);
    $allowedSubscribe->assertOk()
        ->assertJsonPath('success', true);
});
