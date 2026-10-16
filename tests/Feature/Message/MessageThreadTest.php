<?php

use App\Enums\Message\ConversationMemberRole;
use App\Enums\Message\ConversationType;
use App\Events\Message\ConversationCreated;
use App\Events\Message\MessageRead;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
    $this->charlie = User::factory()->create(['name' => 'Charlie']);
});

test('un utilisateur peut creer une conversation directe avec un autre utilisateur', function (): void {
    Event::fake([ConversationCreated::class]);

    Passport::actingAs($this->alice);

    $response = $this->postJson('/api/messages/threads', [
        'peer_id' => $this->bob->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'direct');

    $threadId = $response->json('data.id');
    expect(Conversation::where('id', $threadId)->exists())->toBeTrue();
    expect(ConversationMember::where('conversation_id', $threadId)->count())->toBe(2);

    Event::assertDispatched(ConversationCreated::class);
});

test('la creation d une conversation directe existante est idempotente et reutilise le fil', function (): void {
    Passport::actingAs($this->alice);

    $res1 = $this->postJson('/api/messages/threads', [
        'peer_id' => $this->bob->id,
    ]);
    $res1->assertStatus(201);
    $threadId1 = $res1->json('data.id');

    // Appel concurrent ou répété
    $res2 = $this->postJson('/api/messages/threads', [
        'peer_id' => $this->bob->id,
    ]);
    $res2->assertStatus(201);
    $threadId2 = $res2->json('data.id');

    expect($threadId1)->toBe($threadId2);
    expect(Conversation::where('type', ConversationType::Direct)->count())->toBe(1);
});

test('un utilisateur ne peut pas creer une conversation avec lui meme', function (): void {
    Passport::actingAs($this->alice);

    $response = $this->postJson('/api/messages/threads', [
        'peer_id' => $this->alice->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['peer_id']);
});

test('un utilisateur peut lister ses conversations avec tri et pagination', function (): void {
    Passport::actingAs($this->alice);

    // Créer 2 conversations pour Alice
    $thread1 = Conversation::factory()->create(['created_by_id' => $this->alice->id, 'last_message_at' => now()->subHour()]);
    ConversationMember::create(['conversation_id' => $thread1->id, 'user_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $thread1->id, 'user_id' => $this->bob->id]);

    $thread2 = Conversation::factory()->create(['created_by_id' => $this->alice->id, 'last_message_at' => now()]);
    ConversationMember::create(['conversation_id' => $thread2->id, 'user_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $thread2->id, 'user_id' => $this->charlie->id]);

    // Conversation dont Alice n'est pas membre
    $thread3 = Conversation::factory()->create(['created_by_id' => $this->bob->id]);
    ConversationMember::create(['conversation_id' => $thread3->id, 'user_id' => $this->bob->id]);
    ConversationMember::create(['conversation_id' => $thread3->id, 'user_id' => $this->charlie->id]);

    $response = $this->getJson('/api/messages/threads');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');

    // Vérifier l'ordre par last_message_at desc (thread2 puis thread1)
    $threadIds = collect($response->json('data'))->pluck('id')->all();
    expect($threadIds)->toBe([$thread2->id, $thread1->id]);
});

test('un utilisateur peut creer une conversation contextuelle liee a une annonce', function (): void {
    $listing = Listing::factory()->create(['owner_id' => $this->bob->id]);

    Passport::actingAs($this->alice);

    $response = $this->postJson('/api/messages/threads', [
        'peer_id' => $this->bob->id,
        'listing_id' => $listing->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.listing_id', $listing->id)
        ->assertJsonPath('data.listing.id', $listing->id)
        ->assertJsonPath('data.listing.title', $listing->title);
});

test('un utilisateur peut marquer une conversation comme lue', function (): void {
    Event::fake([MessageRead::class]);

    $thread = Conversation::factory()->create(['created_by_id' => $this->alice->id]);
    ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->alice->id]);
    $bobMember = ConversationMember::create(['conversation_id' => $thread->id, 'user_id' => $this->bob->id]);

    $message = Message::factory()->create([
        'conversation_id' => $thread->id,
        'sender_id' => $this->alice->id,
    ]);

    Passport::actingAs($this->bob);

    $response = $this->postJson("/api/messages/threads/{$thread->id}/read", [
        'message_id' => $message->id,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $bobMember->refresh();
    expect($bobMember->last_read_message_id)->toBe($message->id);
    expect($bobMember->last_read_at)->not->toBeNull();

    Event::assertDispatched(MessageRead::class);
});

test('un utilisateur peut creer un groupe et administrer ses membres', function (): void {
    Passport::actingAs($this->alice);

    $response = $this->postJson('/api/messages/groups', [
        'title' => 'Groupe Test BANGO',
        'members' => [$this->bob->id, $this->charlie->id],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'group')
        ->assertJsonPath('data.title', 'Groupe Test BANGO');

    $groupId = $response->json('data.id');

    // Vérifier qu'Alice est admin
    $aliceMember = ConversationMember::where('conversation_id', $groupId)->where('user_id', $this->alice->id)->first();
    expect($aliceMember->role)->toBe(ConversationMemberRole::Admin);

    // Alice peut ajouter un membre
    $david = User::factory()->create(['name' => 'David']);
    $addResponse = $this->postJson("/api/messages/groups/{$groupId}/members", [
        'members' => [$david->id],
    ]);
    $addResponse->assertStatus(200);
    expect(ConversationMember::where('conversation_id', $groupId)->where('user_id', $david->id)->exists())->toBeTrue();

    // Bob (simple membre) ne peut pas ajouter de membre
    Passport::actingAs($this->bob);
    $eve = User::factory()->create(['name' => 'Eve']);
    $forbiddenAdd = $this->postJson("/api/messages/groups/{$groupId}/members", [
        'members' => [$eve->id],
    ]);
    $forbiddenAdd->assertStatus(403);

    // Alice (admin) peut retirer Bob du groupe
    Passport::actingAs($this->alice);
    $removeResponse = $this->deleteJson("/api/messages/groups/{$groupId}/members/{$this->bob->id}");
    $removeResponse->assertStatus(200);

    $bobMember = ConversationMember::where('conversation_id', $groupId)->where('user_id', $this->bob->id)->first();
    expect($bobMember->left_at)->not->toBeNull();
});

test('le depart du dernier administrateur promeut automatiquement le membre le plus ancien', function (): void {
    Passport::actingAs($this->alice);

    $group = Conversation::factory()->group()->create(['created_by_id' => $this->alice->id]);
    ConversationMember::create([
        'conversation_id' => $group->id,
        'user_id' => $this->alice->id,
        'role' => ConversationMemberRole::Admin,
        'joined_at' => now()->subHours(2),
    ]);
    $bobMember = ConversationMember::create([
        'conversation_id' => $group->id,
        'user_id' => $this->bob->id,
        'role' => ConversationMemberRole::Member,
        'joined_at' => now()->subHour(),
    ]);
    ConversationMember::create([
        'conversation_id' => $group->id,
        'user_id' => $this->charlie->id,
        'role' => ConversationMemberRole::Member,
        'joined_at' => now()->subMinutes(30),
    ]);

    // Alice quitte le groupe
    $response = $this->deleteJson("/api/messages/groups/{$group->id}/members/{$this->alice->id}");
    $response->assertStatus(200);

    // Bob (le plus ancien après Alice) doit avoir été promu ADMIN
    $bobMember->refresh();
    expect($bobMember->role)->toBe(ConversationMemberRole::Admin);
});
