<?php

namespace Tests\Feature\Support;

use App\Enums\Listing\ListingStatus;
use App\Enums\Message\ConversationType;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Support\Report;
use App\Models\User;
use App\Notifications\Listing\ListingFraudAlertNotification;
use App\Services\Admin\ModerationQueueService;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(ModerationRuleSeeder::class);
});

function createFraudModerationOperator(): array
{
    $role = Role::where('slug', 'moderation')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.fraud.'.uniqid().'@bango.app',
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

it('notifies all users who had a conversation about a fake listing when it is removed by moderation (SUP-18)', function () {
    Notification::fake();

    [$operator] = createFraudModerationOperator();

    // 1. Annonce et utilisateurs
    $landlord = User::factory()->create(['name' => 'Bailleur Fictif']);
    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'title' => 'Appartement Haut Standing Bonapriso',
        'status' => ListingStatus::Published,
    ]);

    $prospect1 = User::factory()->create(['name' => 'Prospect Alice']);
    $prospect2 = User::factory()->create(['name' => 'Prospect Bob']);
    $unrelatedUser = User::factory()->create(['name' => 'Utilisateur Sans Conversation']);

    // Conversation pour le prospect 1
    $conversation1 = Conversation::create([
        'listing_id' => $listing->id,
        'type' => ConversationType::Direct,
        'created_by_id' => $prospect1->id,
    ]);
    ConversationMember::create(['conversation_id' => $conversation1->id, 'user_id' => $prospect1->id, 'role' => 'member']);
    ConversationMember::create(['conversation_id' => $conversation1->id, 'user_id' => $landlord->id, 'role' => 'member']);

    // Conversation pour le prospect 2
    $conversation2 = Conversation::create([
        'listing_id' => $listing->id,
        'type' => ConversationType::Direct,
        'created_by_id' => $prospect2->id,
    ]);
    ConversationMember::create(['conversation_id' => $conversation2->id, 'user_id' => $prospect2->id, 'role' => 'member']);
    ConversationMember::create(['conversation_id' => $conversation2->id, 'user_id' => $landlord->id, 'role' => 'member']);

    // 2. Signalement pour fausse annonce
    $reporter = User::factory()->create();
    $report = Report::create([
        'reporter_id' => $reporter->id,
        'reportable_type' => Listing::class,
        'reportable_id' => $listing->id,
        'reason' => ReportReason::FakeListing,
        'details' => 'Cet appartement n\'existe pas à cette adresse.',
        'status' => ReportStatus::Pending,
        'priority' => 'high',
    ]);

    // 3. Application de la décision de modération : retrait du contenu
    $service = app(ModerationQueueService::class);
    $service->applyDecision(
        report: $report,
        action: 'remove',
        justification: 'Fausse annonce immobilière confirmée après vérification.',
        operator: $operator
    );

    // 4. Vérification de la notification envoyée aux prospects
    Notification::assertSentTo($prospect1, ListingFraudAlertNotification::class, function ($notification) use ($listing, $conversation1) {
        $data = $notification->toArray($notification);

        return $data['type'] === 'listing_fraud'
            && $data['listing_id'] === (string) $listing->id
            && $data['listing_title'] === 'Appartement Haut Standing Bonapriso'
            && $data['conversation_id'] === (string) $conversation1->id;
    });

    Notification::assertSentTo($prospect2, ListingFraudAlertNotification::class, function ($notification) use ($listing, $conversation2) {
        $data = $notification->toArray($notification);

        return $data['type'] === 'listing_fraud'
            && $data['listing_id'] === (string) $listing->id
            && $data['conversation_id'] === (string) $conversation2->id;
    });

    // Le bailleur (auteur) et les tiers non concernés ne reçoivent pas ListingFraudAlertNotification
    Notification::assertNotSentTo($landlord, ListingFraudAlertNotification::class);
    Notification::assertNotSentTo($unrelatedUser, ListingFraudAlertNotification::class);

    // L'annonce doit avoir enregistré le retrait
    $refreshedListing = Listing::withTrashed()->find($listing->id);
    expect($refreshedListing->withdrawn_by_bango)->toBeTrue()
        ->and($refreshedListing->withdrawn_by_bango_at)->not->toBeNull();
});

it('provides fraud-alert details via GET /api/listings/{id}/fraud-alert (SUP-18)', function () {
    $landlord = User::factory()->create();
    $prospect = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'title' => 'Villa avec piscine Bonamoussadi',
        'withdrawn_by_bango' => true,
        'withdrawn_by_bango_at' => now()->subHour(),
        'bango_withdrawal_reason' => 'Fausse annonce avérée',
    ]);
    $listing->delete(); // Soft-deleted

    $conversation = Conversation::create([
        'listing_id' => $listing->id,
        'type' => ConversationType::Direct,
        'created_by_id' => $prospect->id,
    ]);
    ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $prospect->id, 'role' => 'member']);
    ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $landlord->id, 'role' => 'member']);

    // Authentifié en tant que prospect
    Passport::actingAs($prospect);

    $response = $this->getJson("/api/listings/{$listing->id}/fraud-alert");

    $response->assertStatus(200)
        ->assertJsonPath('listing_id', (string) $listing->id)
        ->assertJsonPath('title', 'Villa avec piscine Bonamoussadi')
        ->assertJsonPath('conversation_id', (string) $conversation->id);

    expect($response->json('removed_at'))->not->toBeNull();
});
