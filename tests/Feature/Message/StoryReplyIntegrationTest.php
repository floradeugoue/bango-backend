<?php

use App\Enums\Message\MessageType;
use App\Enums\Story\StoryReplyType;
use App\Events\Message\MessageSent;
use App\Events\Story\StoryReplyCreated;
use App\Listeners\Story\CreateConversationFromStoryReply;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\Story\Story;
use App\Models\Story\StoryReply;
use App\Models\Story\StorySlide;
use App\Models\User;
use App\Notifications\Message\NewStoryReplyNotification;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->author = User::factory()->create(['name' => 'Story Author']);
    $this->respondent = User::factory()->create(['name' => 'Respondent User']);

    $this->story = Story::create([
        'user_id' => $this->author->id,
        'title' => 'Ma Story BANGO',
        'status' => 'active',
        'expires_at' => now()->addDay(),
    ]);

    $this->slide = StorySlide::create([
        'story_id' => $this->story->id,
        'media_type' => 'image',
        'media_path' => 'stories/slide1.jpg',
        'media_url' => 'https://bango.test/storage/stories/slide1.jpg',
        'thumbnail_url' => 'https://bango.test/storage/stories/thumb1.jpg',
        'caption' => 'Magnifique coucher de soleil',
        'order' => 1,
    ]);
});

test('l evenement StoryReplyCreated cree automatiquement la conversation et le message associes', function (): void {
    Notification::fake();
    Event::fake([MessageSent::class]);

    $reply = StoryReply::create([
        'story_id' => $this->story->id,
        'story_slide_id' => $this->slide->id,
        'story_author_id' => $this->author->id,
        'user_id' => $this->respondent->id,
        'type' => StoryReplyType::Text,
        'text' => 'Superbe photo, où est-ce pris ?',
        'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(),
        'metadata' => [
            'story_id' => (string) $this->story->id,
            'story_slide_id' => (string) $this->slide->id,
            'author_handle' => $this->author->handle ?? $this->author->name,
            'author_name' => $this->author->name,
            'media_url' => $this->slide->media_url,
            'thumbnail_url' => $this->slide->thumbnail_url,
            'caption' => $this->slide->caption,
            'published_at' => now()->toIso8601String(),
        ],
    ]);

    // Exécuter le listener
    $listener = app(CreateConversationFromStoryReply::class);
    $listener->handle(new StoryReplyCreated($reply));

    $reply->refresh();

    // Vérifier la mise à jour des clés futures
    expect($reply->future_conversation_id)->not->toBeNull();
    expect($reply->future_message_id)->not->toBeNull();
    expect($reply->status)->toBe('processed');

    // Vérifier la conversation créée
    $conversation = Conversation::find($reply->future_conversation_id);
    expect($conversation)->not->toBeNull();
    expect($conversation->isMember($this->respondent->id))->toBeTrue();
    expect($conversation->isMember($this->author->id))->toBeTrue();

    // Vérifier le message créé
    $message = Message::find($reply->future_message_id);
    expect($message)->not->toBeNull();
    expect($message->type)->toBe(MessageType::StoryReply);
    expect($message->sender_id)->toBe($this->respondent->id);
    expect($message->body)->toBe('Superbe photo, où est-ce pris ?');
    expect($message->metadata['story_preview_card']['media_url'])->toBe($this->slide->media_url);

    // Notification envoyée à l'auteur de la Story
    Notification::assertSentTo($this->author, NewStoryReplyNotification::class);
});

test('le listener est strictement idempotent en cas de rejeu d evenement', function (): void {
    Notification::fake();

    $reply = StoryReply::create([
        'story_id' => $this->story->id,
        'story_slide_id' => $this->slide->id,
        'story_author_id' => $this->author->id,
        'user_id' => $this->respondent->id,
        'type' => StoryReplyType::Reaction,
        'reaction' => '🔥',
        'status' => 'pending',
        'idempotency_key' => (string) Str::uuid(),
        'metadata' => [
            'story_id' => (string) $this->story->id,
            'author_name' => $this->author->name,
            'caption' => $this->slide->caption,
        ],
    ]);

    $listener = app(CreateConversationFromStoryReply::class);

    // Premier traitement
    $listener->handle(new StoryReplyCreated($reply));
    $reply->refresh();
    $convId1 = $reply->future_conversation_id;
    $msgId1 = $reply->future_message_id;

    // Deuxième traitement (rejeu de queue ou d'événement)
    $listener->handle(new StoryReplyCreated($reply));
    $reply->refresh();

    expect($reply->future_conversation_id)->toBe($convId1);
    expect($reply->future_message_id)->toBe($msgId1);

    // Il ne doit y avoir qu'un seul message créé
    expect(Message::where('client_message_id', 'story_reply_'.$reply->id)->count())->toBe(1);
    expect(Conversation::count())->toBe(1);
});

test('le snapshot reste intact et consultable meme si la story originale est supprimee', function (): void {
    $reply = StoryReply::create([
        'story_id' => $this->story->id,
        'story_slide_id' => $this->slide->id,
        'story_author_id' => $this->author->id,
        'user_id' => $this->respondent->id,
        'type' => StoryReplyType::Text,
        'text' => 'Comment vas-tu ?',
        'status' => 'pending',
        'metadata' => [
            'story_id' => (string) $this->story->id,
            'author_name' => 'Story Author',
            'caption' => 'Snapshot immuable préservé',
            'media_url' => 'https://bango.test/storage/stories/permanent.jpg',
        ],
    ]);

    $listener = app(CreateConversationFromStoryReply::class);
    $listener->handle(new StoryReplyCreated($reply));

    $reply->refresh();
    $message = Message::find($reply->future_message_id);

    // Supprimer la Story
    $this->slide->delete();
    $this->story->delete();

    // Le message doit toujours contenir le snapshot
    $message->refresh();
    expect($message->metadata['story_preview_card']['caption'])->toBe('Snapshot immuable préservé');
    expect($message->metadata['story_preview_card']['media_url'])->toBe('https://bango.test/storage/stories/permanent.jpg');
});

test('le endpoint reel POST api stories reply declenche l integration messagerie de bout en bout', function (): void {
    Passport::actingAs($this->respondent);

    $response = $this->postJson("/api/stories/{$this->story->id}/reply", [
        'story_slide_id' => $this->slide->id,
        'type' => 'text',
        'text' => 'Réponse via API REST BANGO',
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $response->assertStatus(201);
    $replyId = $response->json('data.id');

    // Vérifier que la réponse Story a été traitée par le listener
    $reply = StoryReply::find($replyId);
    expect($reply->future_conversation_id)->not->toBeNull();
    expect($reply->future_message_id)->not->toBeNull();
    expect($reply->status)->toBe('processed');

    // Vérifier que le message est accessible via l'API messagerie
    $threadResponse = $this->getJson("/api/messages/threads/{$reply->future_conversation_id}/messages");
    $threadResponse->assertStatus(200)
        ->assertJsonFragment([
            'type' => 'story_reply',
            'body' => 'Réponse via API REST BANGO',
        ]);
});
