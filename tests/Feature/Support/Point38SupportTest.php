<?php

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Legal\LegalDocument;
use App\Models\Support\HelpArticle;
use App\Models\Support\SupportTicket;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use Database\Seeders\HelpArticleSeeder;
use Database\Seeders\LegalDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

test('Point 38 : Note et réouverture de tickets de support', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $ticket = SupportTicket::create([
        'reference' => SupportTicket::generateReference(),
        'user_id' => $user->id,
        'subject' => 'Problème de connexion',
        'category' => TicketCategory::AccountAuth,
        'status' => TicketStatus::Resolved,
        'priority' => TicketPriority::Normal,
        'resolved_at' => now(),
    ]);

    // 1. Noter le ticket
    $rateResponse = $this->postJson("/api/support/tickets/{$ticket->id}/rate", [
        'rating' => 5,
        'rating_comment' => 'Service client réactif et efficace !',
    ]);

    $rateResponse->assertOk()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.rating_comment', 'Service client réactif et efficace !');

    $this->assertDatabaseHas('support_tickets', [
        'id' => $ticket->id,
        'rating' => 5,
        'rating_comment' => 'Service client réactif et efficace !',
    ]);

    // 2. Réouverture du ticket
    $reopenResponse = $this->postJson("/api/support/tickets/{$ticket->id}/reopen", [
        'reason' => 'Le problème est réapparu après déconnexion.',
    ]);

    $reopenResponse->assertOk()
        ->assertJsonPath('data.status.value', TicketStatus::Open->value);

    expect($ticket->fresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->fresh()->reopened_at)->not->toBeNull();
});

test('Point 38 : Consultation des sanctions et avertissements reçus', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $admin = User::factory()->create();

    UserAccountSanction::create([
        'user_id' => $user->id,
        'operator_id' => $admin->id,
        'type' => 'warning',
        'level' => 1,
        'reason' => 'Non respect des règles de bienséance dans les commentaires.',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/me/sanctions');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'warning')
        ->assertJsonPath('data.0.reason', 'Non respect des règles de bienséance dans les commentaires.');
});

test('Point 38 : Signalement formel d\'atteinte aux droits d\'auteur', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = $this->postJson('/api/support/copyright-claims', [
        'claimant_name' => 'Jean Dupont',
        'claimant_email' => 'jean.dupont@example.com',
        'content_type' => 'post',
        'content_id' => '123',
        'work_title' => 'Photographie originale de villa',
        'proof_url' => 'https://example.com/proof.pdf',
        'reason' => 'Utilisation sans autorisation de mes photos de portfolio.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.content_type', 'post')
        ->assertJsonPath('data.content_id', '123');

    $this->assertDatabaseHas('copyright_claims', [
        'claimant_id' => $user->id,
        'content_id' => '123',
        'work_title' => 'Photographie originale de villa',
    ]);
});

test('Point 38 : Base de données enrichie avec articles d\'aide et documents légaux', function () {
    $this->seed(HelpArticleSeeder::class);
    $this->seed(LegalDocumentSeeder::class);

    expect(HelpArticle::count())->toBeGreaterThanOrEqual(4);
    expect(LegalDocument::count())->toBeGreaterThanOrEqual(3);

    // Vérifier consultation de document légal
    $cgu = LegalDocument::where('slug', 'cgu')->first();
    expect($cgu)->not->toBeNull()
        ->and($cgu->is_published)->toBeTrue();

    // Vérifier consultation d'article d'aide
    $article = HelpArticle::where('slug', 'comment-reserver-une-visite')->first();
    expect($article)->not->toBeNull()
        ->and($article->is_published)->toBeTrue();
});
