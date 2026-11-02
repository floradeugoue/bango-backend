<?php

use App\Enums\Message\MessageType;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->creator = User::factory()->create(['name' => 'Creator']);
    $this->member1 = User::factory()->create(['name' => 'Member One']);
    $this->member2 = User::factory()->create(['name' => 'Member Two']);
    $this->outsider = User::factory()->create(['name' => 'Outsider']);

    Passport::actingAs($this->creator);
    $response = $this->postJson('/api/messages/groups', [
        'title' => 'Groupe Test BANGO',
        'members' => [$this->member1->id, $this->member2->id],
    ]);
    $response->assertStatus(201);
    $this->groupId = $response->json('data.id');
    $this->group = Conversation::findOrFail($this->groupId);
});

test('nommer ou retirer un admin et verifier is_admin dans ConversationMemberResource', function (): void {
    Passport::actingAs($this->creator);

    // 1. Nommer member1 admin
    $makeAdminRes = $this->postJson("/api/messages/groups/{$this->groupId}/admins/{$this->member1->id}");
    $makeAdminRes->assertOk();

    // Vérifier is_admin dans la ressource renvoyée
    $members = $makeAdminRes->json('group.members');
    $member1Data = collect($members)->firstWhere('user_id', $this->member1->id);
    expect($member1Data['is_admin'])->toBeTrue()
        ->and($member1Data['role'])->toBe('admin');

    // Vérifier le message système posté dans le fil
    $lastMsg = Message::where('conversation_id', $this->groupId)->latest('created_at')->first();
    expect($lastMsg)->not->toBeNull()
        ->and($lastMsg->type)->toBe(MessageType::System)
        ->and($lastMsg->body)->toContain('a été nommé(e) administrateur du groupe');

    // 2. Retirer admin de member1
    $removeAdminRes = $this->deleteJson("/api/messages/groups/{$this->groupId}/admins/{$this->member1->id}");
    $removeAdminRes->assertOk();

    $membersAfter = $removeAdminRes->json('group.members');
    $member1DataAfter = collect($membersAfter)->firstWhere('user_id', $this->member1->id);
    expect($member1DataAfter['is_admin'])->toBeFalse()
        ->and($member1DataAfter['role'])->toBe('member');

    $lastMsg2 = Message::where('conversation_id', $this->groupId)->latest('created_at')->first();
    expect($lastMsg2->type)->toBe(MessageType::System)
        ->and($lastMsg2->body)->toContain("n'est plus administrateur du groupe");
});

test('admins_only limite l ecriture aux seuls administrateurs', function (): void {
    Passport::actingAs($this->creator);

    // Activer admins_only
    $updateRes = $this->putJson("/api/messages/groups/{$this->groupId}", [
        'admins_only' => true,
    ]);
    $updateRes->assertOk();
    expect($updateRes->json('group.admins_only'))->toBeTrue();

    // Message système généré
    $sysMsg = Message::where('conversation_id', $this->groupId)->latest('created_at')->first();
    expect($sysMsg->type)->toBe(MessageType::System)
        ->and($sysMsg->body)->toContain('seuls les administrateurs');

    // Member 1 (non admin) tente d'écrire -> 403
    Passport::actingAs($this->member1);
    $sendForbidden = $this->postJson("/api/messages/threads/{$this->groupId}/messages", [
        'body' => 'Bonjour tout le monde',
    ]);
    $sendForbidden->assertStatus(403);

    // Creator (admin) écrit -> 201
    Passport::actingAs($this->creator);
    $sendAllowed = $this->postJson("/api/messages/threads/{$this->groupId}/messages", [
        'body' => 'Message de l\'admin',
    ]);
    $sendAllowed->assertStatus(201);

    // Désactiver admins_only
    $disableRes = $this->putJson("/api/messages/groups/{$this->groupId}", [
        'admins_only' => false,
    ]);
    $disableRes->assertOk();

    // Member 1 peut maintenant écrire
    Passport::actingAs($this->member1);
    $sendNowOk = $this->postJson("/api/messages/threads/{$this->groupId}/messages", [
        'body' => 'Je peux écrire maintenant',
    ]);
    $sendNowOk->assertStatus(201);
});

test('supprimer un groupe rend le fil en lecture seule avec message systeme', function (): void {
    Passport::actingAs($this->creator);

    // Suppression du groupe par le créateur
    $deleteRes = $this->deleteJson("/api/messages/groups/{$this->groupId}");
    $deleteRes->assertOk();

    // Le groupe est soft-deleted et is_read_only = true
    $freshGroup = Conversation::withTrashed()->find($this->groupId);
    expect($freshGroup->trashed())->toBeTrue()
        ->and($freshGroup->is_read_only)->toBeTrue();

    // Message système « Le groupe a été supprimé »
    $sysMsg = Message::where('conversation_id', $this->groupId)->latest('created_at')->first();
    expect($sysMsg->type)->toBe(MessageType::System)
        ->and($sysMsg->body)->toBe('Le groupe a été supprimé');

    // N'importe qui tente d'écrire -> 403 (lecture seule)
    Passport::actingAs($this->member1);
    $attemptMsg = $this->postJson("/api/messages/threads/{$this->groupId}/messages", [
        'body' => 'Coucou encore là ?',
    ]);
    $attemptMsg->assertStatus(403);
});

test('partager un lien d invitation, le revoquer et rejoindre le groupe via le lien', function (): void {
    Passport::actingAs($this->creator);

    // 1. Obtenir / générer le lien
    $linkRes = $this->postJson("/api/messages/groups/{$this->groupId}/invite-link");
    $linkRes->assertOk()
        ->assertJsonStructure(['url', 'token']);
    $token = $linkRes->json('token');
    expect($token)->not->toBeEmpty();

    // 2. Outsider rejoint via le token
    Passport::actingAs($this->outsider);
    $joinRes = $this->postJson("/api/messages/groups/join/{$token}");
    $joinRes->assertOk()
        ->assertJsonPath('group.id', $this->groupId);

    expect(ConversationMember::where('conversation_id', $this->groupId)->where('user_id', $this->outsider->id)->exists())
        ->toBeTrue();

    // Message système d'adhésion
    $joinSysMsg = Message::where('conversation_id', $this->groupId)->latest('created_at')->first();
    expect($joinSysMsg->type)->toBe(MessageType::System)
        ->and($joinSysMsg->body)->toContain('a rejoint le groupe via le lien d\'invitation');

    // 3. Admin révoque le lien
    Passport::actingAs($this->creator);
    $revokeRes = $this->deleteJson("/api/messages/groups/{$this->groupId}/invite-link");
    $revokeRes->assertOk();

    $freshGroup = Conversation::find($this->groupId);
    expect($freshGroup->invite_token)->toBeNull();

    // Message système de révocation
    $revokeSysMsg = Message::where('conversation_id', $this->groupId)->latest('created_at')->first();
    expect($revokeSysMsg->type)->toBe(MessageType::System)
        ->and($revokeSysMsg->body)->toBe('Le lien d\'invitation a été révoqué');

    // 4. Un autre utilisateur tente de rejoindre avec l'ancien token -> 422
    $anotherUser = User::factory()->create();
    Passport::actingAs($anotherUser);
    $failJoin = $this->postJson("/api/messages/groups/join/{$token}");
    $failJoin->assertStatus(422);
});
