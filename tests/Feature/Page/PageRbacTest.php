<?php

use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);

    $this->owner = User::factory()->create(['name' => 'Paul Propriétaire']);
    $this->editor = User::factory()->create(['name' => 'Éric Éditeur']);
    $this->moderator = User::factory()->create(['name' => 'Marc Modérateur']);
    $this->stranger = User::factory()->create(['name' => 'Sam Inconnu']);

    $this->page = Page::factory()->create([
        'owner_id' => $this->owner->id,
        'name' => 'Entreprise Test RBAC',
    ]);

    $this->ownerMember = PageMember::create([
        'page_id' => $this->page->id,
        'user_id' => $this->owner->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $this->editorMember = PageMember::create([
        'page_id' => $this->page->id,
        'user_id' => $this->editor->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $this->moderatorMember = PageMember::create([
        'page_id' => $this->page->id,
        'user_id' => $this->moderator->id,
        'role' => PageRole::Moderator,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);
});

test('un propriétaire peut inviter des membres et changer leurs rôles', function () {
    Passport::actingAs($this->owner);

    $newUser = User::factory()->create();

    // 1. Invitation
    $resInvite = $this->postJson("/api/pages/{$this->page->id}/members/invite", [
        'user_id' => $newUser->id,
        'role' => PageRole::Editor->value,
    ]);
    $resInvite->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.role', 'editor');

    // 2. Modification du rôle de l'éditeur existant vers modérateur
    $resRole = $this->putJson("/api/pages/{$this->page->id}/members/{$this->editorMember->id}/role", [
        'role' => PageRole::Moderator->value,
    ]);
    $resRole->assertOk()
        ->assertJsonPath('data.role', 'moderator');
});

test('un éditeur ne peut pas modifier les informations de la page ni administrer les membres', function () {
    Passport::actingAs($this->editor);

    // Tentative de modifier la page
    $resUpdate = $this->putJson("/api/pages/{$this->page->id}", [
        'name' => 'Piratage par éditeur',
    ]);
    $resUpdate->assertForbidden();

    // Tentative d'inviter un membre
    $resInvite = $this->postJson("/api/pages/{$this->page->id}/members/invite", [
        'user_id' => $this->stranger->id,
        'role' => PageRole::Moderator->value,
    ]);
    $resInvite->assertForbidden();

    // Tentative de supprimer la page
    $resDelete = $this->deleteJson("/api/pages/{$this->page->id}");
    $resDelete->assertForbidden();
});

test('un modérateur ne peut pas publier au nom de la page', function () {
    Passport::actingAs($this->moderator);

    // Tentative de publication Square au nom de la page
    $response = $this->postJson('/api/square-cr1/posts', [
        'body' => 'Post non autorisé par modérateur',
        'as_page_id' => $this->page->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['as_page_id']);
});

test('un éditeur autorisé peut publier un post Square au nom de la page', function () {
    Passport::actingAs($this->editor);

    $response = $this->postJson('/api/square-cr1/posts', [
        'body' => 'Publication officielle au nom de la Page par l\'Éditeur.',
        'as_page_id' => $this->page->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.is_page_post', true)
        ->assertJsonPath('data.page.id', $this->page->id)
        ->assertJsonPath('data.author_name', $this->page->name);

    $this->assertDatabaseHas('posts', [
        'user_id' => $this->editor->id,
        'page_id' => $this->page->id,
    ]);
});

test('un utilisateur extérieur ne peut pas modifier la page ni accéder aux membres', function () {
    Passport::actingAs($this->stranger);

    $resUpdate = $this->putJson("/api/pages/{$this->page->id}", [
        'name' => 'Attaque externe',
    ]);
    $resUpdate->assertForbidden();

    $resMembers = $this->getJson("/api/pages/{$this->page->id}/members");
    $resMembers->assertForbidden();
});

test('un membre révoqué perd immédiatement ses accès et ne peut plus publier (EDGE-02)', function () {
    Passport::actingAs($this->owner);

    // Le propriétaire révoque l'éditeur
    $resRevoke = $this->deleteJson("/api/pages/{$this->page->id}/members/{$this->editorMember->id}");
    $resRevoke->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    // L'éditeur tente immédiatement de publier un post au nom de la page
    Passport::actingAs($this->editor);
    $resPublish = $this->postJson('/api/square-cr1/posts', [
        'body' => 'Tentative post après révocation',
        'as_page_id' => $this->page->id,
    ]);

    $resPublish->assertStatus(422)
        ->assertJsonValidationErrors(['as_page_id']);
});

test('un invité peut accepter ou refuser son invitation', function () {
    $invitedUser = User::factory()->create();

    Passport::actingAs($this->owner);
    $resInvite = $this->postJson("/api/pages/{$this->page->id}/members/invite", [
        'user_id' => $invitedUser->id,
        'role' => PageRole::Editor->value,
    ]);
    $memberId = $resInvite->json('data.id');

    // L'invité accepte
    Passport::actingAs($invitedUser);
    $resAccept = $this->postJson("/api/page-members/{$memberId}/accept");
    $resAccept->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    expect($this->page->hasMember($invitedUser, PageRole::Editor))->toBeTrue();
});

test('invitation envoie notification page_invitation avec member_id et apparait dans GET /me/page-invitations', function () {
    $invitedUser = User::factory()->create([
        'name' => 'jeanpaul',
        'display_name' => 'Jean-Paul Kamga',
    ]);

    Passport::actingAs($this->owner);
    $resInvite = $this->postJson("/api/pages/{$this->page->id}/members/invite", [
        'user_id' => $invitedUser->id,
        'role' => PageRole::Editor->value,
    ]);
    $resInvite->assertStatus(201)
        ->assertJsonPath('data.user.name', 'Jean-Paul Kamga');

    $memberId = $resInvite->json('data.id');

    // Vérifier la notification reçue en base
    $notification = $invitedUser->notifications()->first();
    expect($notification)->not->toBeNull();
    expect($notification->data['type'])->toBe('page_invitation');
    expect($notification->data['member_id'])->toBe($memberId);
    expect($notification->data['page_id'])->toBe($this->page->id);
    expect($notification->data['page_name'])->toBe($this->page->name);
    expect($notification->data['role'])->toBe('editor');

    // Vérifier GET /api/me/page-invitations
    Passport::actingAs($invitedUser);
    $resInvitations = $this->getJson('/api/me/page-invitations');
    $resInvitations->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $memberId)
        ->assertJsonPath('data.0.page_id', $this->page->id)
        ->assertJsonPath('data.0.role', 'editor')
        ->assertJsonPath('data.0.user.name', 'Jean-Paul Kamga');
});
