<?php

use App\Enums\Message\ConversationMemberRole;
use App\Enums\Message\ConversationType;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Events\Message\ConversationCreated;
use App\Events\Message\MessageRead;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
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

test('la liste des fils de discussion utilise display_name et charge avatar_url pour les membres et le dernier message', function (): void {
    $ben = User::factory()->create([
        'name' => 'msg.b+1793885862417',
        'display_name' => 'Ben Messages',
        'avatar_url' => 'https://example.com/ben.jpg',
    ]);

    $thread = Conversation::factory()->create([
        'created_by_id' => $ben->id,
        'status' => 'active',
    ]);

    ConversationMember::create([
        'conversation_id' => $thread->id,
        'user_id' => $this->alice->id,
        'role' => ConversationMemberRole::Member,
    ]);

    ConversationMember::create([
        'conversation_id' => $thread->id,
        'user_id' => $ben->id,
        'role' => ConversationMemberRole::Member,
    ]);

    $message = Message::factory()->create([
        'conversation_id' => $thread->id,
        'sender_id' => $ben->id,
        'body' => 'Salut Alice !',
    ]);

    $thread->update([
        'latest_message_id' => $message->id,
        'last_message_at' => now(),
    ]);

    Passport::actingAs($this->alice);

    $response = $this->getJson('/api/messages/threads');

    $response->assertStatus(200);

    $threads = $response->json('data');
    expect($threads)->not->toBeEmpty();

    $targetThread = collect($threads)->firstWhere('id', (string) $thread->id);
    expect($targetThread)->not->toBeNull()
        ->and($targetThread['title'])->toBe('Ben Messages');

    $benMember = collect($targetThread['members'])->firstWhere('user_id', $ben->id);
    expect($benMember['user']['name'])->toBe('Ben Messages')
        ->and($benMember['user']['avatar_url'])->toBe('https://example.com/ben.jpg');

    expect($targetThread['latest_message']['sender']['name'])->toBe('Ben Messages')
        ->and($targetThread['latest_message']['sender']['avatar_url'])->toBe('https://example.com/ben.jpg');
});

test('un utilisateur peut ecrire a une page via page_id, reutiliser le fil existant, et le fil apparait dans l inbox de la page et pour chaque membre habilite sans exposer le proprietaire', function (): void {
    $owner = User::factory()->create(['name' => 'Secret Owner', 'display_name' => 'Secret Owner']);
    $editor = User::factory()->create(['name' => 'Page Editor', 'display_name' => 'Page Editor']);
    $moderator = User::factory()->create(['name' => 'Page Moderator', 'display_name' => 'Page Moderator']);
    $pendingMember = User::factory()->create(['name' => 'Pending Member', 'display_name' => 'Pending Member']);

    $page = Page::factory()->create([
        'owner_id' => $owner->id,
        'name' => 'Agence Immo Douala',
        'handle' => 'agence-immo-douala',
        'logo_url' => 'https://example.com/page-logo.jpg',
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $owner->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);
    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $editor->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);
    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $moderator->id,
        'role' => PageRole::Moderator,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);
    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $pendingMember->id,
        'role' => PageRole::Moderator,
        'status' => PageMemberStatus::Pending,
    ]);

    // 1. Alice écrit à la page via POST /api/messages/threads avec page_id
    Passport::actingAs($this->alice);

    $res1 = $this->postJson('/api/messages/threads', [
        'page_id' => $page->id,
    ]);

    $res1->assertStatus(201)
        ->assertJsonPath('data.type', 'page')
        ->assertJsonPath('data.page_id', $page->id)
        ->assertJsonPath('data.page.id', $page->id)
        ->assertJsonPath('data.page.name', 'Agence Immo Douala')
        ->assertJsonPath('data.title', 'Agence Immo Douala');

    $threadId = $res1->json('data.id');

    // Le client ne voit pas l'identité personnelle du propriétaire ou des gestionnaires dans members
    $memberUserIdsForClient = collect($res1->json('data.members'))->pluck('user_id')->all();
    expect($memberUserIdsForClient)->toBe([$this->alice->id]);

    // 2. Un second appel réutilise le fil existant entre Alice et cette page
    $res2 = $this->postJson('/api/messages/threads', [
        'page_id' => $page->id,
    ]);
    $res2->assertStatus(201);
    expect($res2->json('data.id'))->toBe($threadId);
    expect(Conversation::where('page_id', $page->id)->count())->toBe(1);

    // 3. Alice envoie un message dans le fil
    $msgRes = $this->postJson("/api/messages/threads/{$threadId}/messages", [
        'body' => 'Bonjour, avez-vous des appartements disponibles à Bonapriso ?',
    ]);
    $msgRes->assertStatus(201);

    // 4. Le fil apparaît dans GET /api/pages/{page}/inbox pour les membres habilités
    Passport::actingAs($owner);
    $inboxRes = $this->getJson("/api/pages/{$page->id}/inbox");
    $inboxRes->assertStatus(200);
    $inboxIds = collect($inboxRes->json('data'))->pluck('id')->all();
    expect($inboxIds)->toContain($threadId);

    // 5. Le fil apparaît aussi pour chaque membre qui a le droit de répondre (Owner, Editor, Moderator), mais pas un membre non accepté
    foreach ([$owner, $editor, $moderator] as $responder) {
        Passport::actingAs($responder);
        $threadsRes = $this->getJson('/api/messages/threads');
        $threadsRes->assertStatus(200);
        $ids = collect($threadsRes->json('data'))->pluck('id')->all();
        expect($ids)->toContain($threadId);
    }

    Passport::actingAs($pendingMember);
    $pendingThreads = $this->getJson('/api/messages/threads');
    $pendingThreads->assertStatus(200);
    expect(collect($pendingThreads->json('data'))->pluck('id')->all())->not->toContain($threadId);

    // 6. Un modérateur de la page peut répondre au fil
    Passport::actingAs($moderator);
    $replyRes = $this->postJson("/api/messages/threads/{$threadId}/messages", [
        'body' => 'Bonjour Alice, oui nous avons 2 T3 disponibles à Bonapriso.',
    ]);
    $replyRes->assertStatus(201);
    expect(Message::find($replyRes->json('data.id'))?->page_id)->toBe($page->id);
});
