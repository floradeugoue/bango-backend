<?php

use App\Enums\Support\AppealStatus;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\Report;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->reporter = User::factory()->create(['name' => 'Alice Vigilante']);
    $this->targetUser = User::factory()->create(['name' => 'Bob Auteur']);
});

// =========================================================================
// 1. SIGNALEMENTS UNIVERSELS (POST /api/reports)
// =========================================================================

test('POST /api/reports permet de signaler un Post Square avec motif et détails', function () {
    Passport::actingAs($this->reporter);

    $post = Post::factory()->create([
        'user_id' => $this->targetUser->id,
        'body' => 'Contenu suspect avec lien douteux',
    ]);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $post->id,
        'reason' => ReportReason::Spam->value,
        'details' => 'Ce post diffuse du phishing évident.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.target_type', 'post')
        ->assertJsonPath('data.target_id', (string) $post->id)
        ->assertJsonPath('data.reason.value', ReportReason::Spam->value)
        ->assertJsonPath('data.status.value', ReportStatus::Pending->value);

    $this->assertDatabaseHas('reports', [
        'reporter_id' => $this->reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => (string) $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);
});

test('POST /api/reports permet de signaler un Reel', function () {
    Passport::actingAs($this->reporter);

    $reel = Reel::factory()->create([
        'user_id' => $this->targetUser->id,
        'caption' => 'Vidéo violente',
    ]);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'reel',
        'target_id' => (string) $reel->id,
        'reason' => ReportReason::Violence->value,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.target_type', 'reel')
        ->assertJsonPath('data.reason.value', ReportReason::Violence->value);
});

test('POST /api/reports permet de signaler une annonce immobilière', function () {
    Passport::actingAs($this->reporter);

    $listing = Listing::factory()->create([
        'owner_id' => $this->targetUser->id,
        'created_by_id' => $this->targetUser->id,
        'title' => 'Villa avec loyer anormalement bas',
    ]);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'listing',
        'target_id' => (string) $listing->id,
        'reason' => ReportReason::FakeListing->value,
        'details' => 'Le propriétaire demande un acompte Western Union avant visite.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.target_type', 'listing')
        ->assertJsonPath('data.reason.value', ReportReason::FakeListing->value);
});

test('POST /api/reports permet de signaler un profil utilisateur', function () {
    Passport::actingAs($this->reporter);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'user',
        'target_id' => (string) $this->targetUser->id,
        'reason' => ReportReason::Harassment->value,
        'details' => 'Harcèlement répété en messages privés.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.target_type', 'user')
        ->assertJsonPath('data.target_id', (string) $this->targetUser->id);
});

test('POST /api/reports permet de signaler une Page Professionnelle', function () {
    Passport::actingAs($this->reporter);

    $page = Page::factory()->create([
        'owner_id' => $this->targetUser->id,
        'name' => 'Fausse Agence Scam',
    ]);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'page',
        'target_id' => (string) $page->id,
        'reason' => ReportReason::Fraud->value,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.target_type', 'page')
        ->assertJsonPath('data.target_id', (string) $page->id);
});

test('POST /api/reports permet de signaler un Message privé', function () {
    Passport::actingAs($this->reporter);

    $conversation = Conversation::create([
        'type' => 'direct',
        'created_by_id' => $this->targetUser->id,
    ]);

    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $this->targetUser->id,
        'body' => 'Menace explicite',
        'type' => 'text',
    ]);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'message',
        'target_id' => (string) $message->id,
        'reason' => ReportReason::Harassment->value,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.target_type', 'message')
        ->assertJsonPath('data.target_id', (string) $message->id);
});

test('POST /api/reports rejette les types de cibles non pris en charge', function () {
    Passport::actingAs($this->reporter);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'unknown_item',
        'target_id' => '123',
        'reason' => ReportReason::Spam->value,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['target_type']);
});

test('POST /api/reports renvoie 404 si la ressource cible est introuvable', function () {
    Passport::actingAs($this->reporter);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => '9999999',
        'reason' => ReportReason::Spam->value,
    ]);

    $response->assertStatus(404);
});

test('POST /api/reports interdit l\'auto-signalement de son propre contenu ou profil', function () {
    Passport::actingAs($this->reporter);

    $myPost = Post::factory()->create([
        'user_id' => $this->reporter->id,
    ]);

    $response = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $myPost->id,
        'reason' => ReportReason::InappropriateContent->value,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Vous ne pouvez pas signaler votre propre contenu ou votre propre profil.');
});

test('POST /api/reports déduplique les signalements répétés pour la même cible', function () {
    Passport::actingAs($this->reporter);

    $post = Post::factory()->create(['user_id' => $this->targetUser->id]);

    // Premier signalement
    $first = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $post->id,
        'reason' => ReportReason::Spam->value,
    ]);
    $first->assertCreated();

    // Deuxième signalement identique
    $second = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $post->id,
        'reason' => ReportReason::Spam->value,
    ]);

    $second->assertOk();
    expect(Report::where('reporter_id', $this->reporter->id)->count())->toBe(1);
});

test('GET /api/reports/mine liste uniquement les signalements de l\'utilisateur connecté', function () {
    Passport::actingAs($this->reporter);

    $post = Post::factory()->create(['user_id' => $this->targetUser->id]);

    Report::create([
        'reporter_id' => $this->reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => (string) $post->id,
        'reason' => ReportReason::Spam,
        'status' => ReportStatus::Pending,
    ]);

    Report::create([
        'reporter_id' => $this->targetUser->id,
        'reportable_type' => Post::class,
        'reportable_id' => (string) $post->id,
        'reason' => ReportReason::Fraud,
        'status' => ReportStatus::Pending,
    ]);

    $response = $this->getJson('/api/reports/mine');

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

// =========================================================================
// 2. RECOURS DE MODÉRATION (POST /api/moderation/appeals)
// =========================================================================

test('POST /api/moderation/appeals permet à un utilisateur suspendu de contester sa sanction', function () {
    $suspendedUser = User::factory()->create([
        'status' => 'suspended',
        'suspension_reason' => 'Activité suspecte',
    ]);

    Passport::actingAs($suspendedUser);

    $response = $this->postJson('/api/moderation/appeals', [
        'sanction_type' => 'account_suspension',
        'reason' => 'Compte piraté',
        'explanation' => 'Mon compte a été compromis depuis un cybercafé, je n\'ai pas commis cette infraction.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.sanction_type', 'account_suspension')
        ->assertJsonPath('data.status.value', AppealStatus::Pending->value);

    $this->assertDatabaseHas('moderation_appeals', [
        'user_id' => $suspendedUser->id,
        'sanction_type' => 'account_suspension',
        'status' => AppealStatus::Pending->value,
    ]);

    // RÈGLE MÉTIER CRUCIALE : Le dépôt du recours NE LÈVE PAS automatiquement la suspension
    expect($suspendedUser->fresh()->status)->toBe('suspended');
});

test('POST /api/moderation/appeals rejette une contestation si le compte n\'est pas suspendu', function () {
    Passport::actingAs($this->reporter); // status = 'active'

    $response = $this->postJson('/api/moderation/appeals', [
        'sanction_type' => 'account_suspension',
        'reason' => 'Erreur',
        'explanation' => 'Je veux contester mais je ne suis même pas suspendu.',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Votre compte ne fait l\'objet d\'aucune suspension active.');
});

test('POST /api/moderation/appeals empêche les recours en doublon pour une même sanction', function () {
    $suspendedUser = User::factory()->create([
        'status' => 'suspended',
    ]);

    Passport::actingAs($suspendedUser);

    ModerationAppeal::create([
        'user_id' => $suspendedUser->id,
        'sanction_type' => 'account_suspension',
        'reason' => 'Déjà déposé',
        'explanation' => 'Explication déjà fournie en attente d\'examen.',
        'status' => AppealStatus::Pending,
    ]);

    $response = $this->postJson('/api/moderation/appeals', [
        'sanction_type' => 'account_suspension',
        'reason' => 'Deuxième recours immédiat',
        'explanation' => 'Je renvoie une contestation.',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Un recours pour cette sanction est déjà en cours d\'instruction.');
});
