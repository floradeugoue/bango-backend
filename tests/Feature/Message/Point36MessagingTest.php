<?php

use App\Enums\Message\ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Story\StoryAudience;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use App\Models\User;
use App\Services\Message\MessageService;
use App\Services\Message\MessageThreadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->alice = User::factory()->create(['name' => 'Alice Doe', 'handle' => 'alicedoe']);
    $this->bob = User::factory()->create(['name' => 'Bob Smith', 'handle' => 'bobsmith']);
    $this->charlie = User::factory()->create(['name' => 'Charlie Day', 'handle' => 'charlieday']);

    $this->threadService = app(MessageThreadService::class);
    $this->messageService = app(MessageService::class);
});

test('messages can receive reactions and be removed', function (): void {
    $conv = $this->threadService->getOrCreateDirectConversation($this->alice, $this->bob->id);
    $conv->update(['status' => 'active']);

    $message = $this->messageService->sendMessage($conv, $this->alice, ['body' => 'Bonjour Bob']);

    Passport::actingAs($this->bob);
    $reactResponse = $this->postJson("/api/messages/messages/{$message->id}/reactions", [
        'emoji' => '❤️',
    ]);
    $reactResponse->assertOk();
    expect($reactResponse->json('reactions'))->toHaveCount(1)
        ->and($reactResponse->json('reactions.0.emoji'))->toBe('❤️')
        ->and($reactResponse->json('reactions.0.user_id'))->toBe($this->bob->id);

    // Remove reaction
    $removeResponse = $this->deleteJson("/api/messages/messages/{$message->id}/reactions");
    $removeResponse->assertOk();
    expect($removeResponse->json('reactions'))->toBeEmpty();
});

test('typing indicators can be set and retrieved', function (): void {
    $conv = $this->threadService->getOrCreateDirectConversation($this->alice, $this->bob->id);
    $conv->update(['status' => 'active']);

    Passport::actingAs($this->alice);
    $this->postJson("/api/messages/threads/{$conv->id}/typing")->assertOk();

    Passport::actingAs($this->bob);
    $response = $this->getJson("/api/messages/threads/{$conv->id}/typing");
    $response->assertOk();
    expect($response->json('typing'))->toHaveCount(1)
        ->and($response->json('typing.0.id'))->toBe($this->alice->id);
});

test('thread can be muted and archived', function (): void {
    $conv = $this->threadService->getOrCreateDirectConversation($this->alice, $this->bob->id);
    $conv->update(['status' => 'active']);

    Passport::actingAs($this->alice);

    // Mute
    $this->postJson("/api/messages/threads/{$conv->id}/mute")->assertOk();
    $member = $conv->members()->where('user_id', $this->alice->id)->first();
    expect($member->is_muted)->toBeTrue();

    // Unmute
    $this->postJson("/api/messages/threads/{$conv->id}/unmute")->assertOk();
    expect($member->fresh()->is_muted)->toBeFalse();

    // Archive
    $this->postJson("/api/messages/threads/{$conv->id}/archive")->assertOk();
    expect($conv->fresh()->metadata['archived_by'])->toContain($this->alice->id);

    // Unarchive
    $this->postJson("/api/messages/threads/{$conv->id}/unarchive")->assertOk();
    expect($conv->fresh()->metadata['archived_by'])->not->toContain($this->alice->id);
});

test('messages are paginated newest first (descending)', function (): void {
    $conv = $this->threadService->getOrCreateDirectConversation($this->alice, $this->bob->id);
    $conv->update(['status' => 'active']);

    $m1 = $this->messageService->sendMessage($conv, $this->alice, ['body' => 'Premier message']);
    $m2 = $this->messageService->sendMessage($conv, $this->alice, ['body' => 'Deuxieme message']);

    Passport::actingAs($this->bob);
    $response = $this->getJson("/api/messages/threads/{$conv->id}/messages");
    $response->assertOk();

    $messages = $response->json('data');
    expect($messages[0]['id'])->toBe($m2->id)
        ->and($messages[1]['id'])->toBe($m1->id);
});

test('recipient cannot write in an unaccepted pending request', function (): void {
    // Alice sends a message request to Bob (stranger)
    $conv = $this->threadService->getOrCreateDirectConversation($this->alice, $this->bob->id);
    expect($conv->status)->toBe('pending')
        ->and($conv->created_by_id)->toBe($this->alice->id);

    // Bob attempts to write before accepting -> forbidden
    Passport::actingAs($this->bob);
    $response = $this->postJson("/api/messages/threads/{$conv->id}/messages", [
        'body' => 'Tentative de reponse non acceptee',
    ]);
    $response->assertStatus(403);
});

test('replying to a story creates a message in conversation', function (): void {
    $story = Story::create([
        'user_id' => $this->alice->id,
        'audience' => StoryAudience::Everyone,
        'allow_replies' => true,
        'published_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);
    $slide = StorySlide::create([
        'story_id' => $story->id,
        'position' => 1,
        'media_path' => 'stories/slide.jpg',
        'media_url' => 'https://example.com/slide.jpg',
        'type' => 'image',
    ]);

    Passport::actingAs($this->bob);
    $resp = $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'text',
        'text' => 'Magnifique story !',
        'slide_id' => $slide->id,
    ]);
    $resp->assertCreated();

    // Verifier qu'un message a bien ete cree dans le fil de discussion
    $conv = Conversation::query()
        ->where('type', ConversationType::Direct)
        ->whereHas('members', fn ($q) => $q->where('user_id', $this->alice->id))
        ->whereHas('members', fn ($q) => $q->where('user_id', $this->bob->id))
        ->first();

    expect($conv)->not->toBeNull();
    $lastMsg = $conv->messages()->latest('created_at')->first();
    expect($lastMsg)->not->toBeNull()
        ->and($lastMsg->body)->toBe('Magnifique story !')
        ->and($lastMsg->type)->toBe(MessageType::StoryReply);
});
