<?php

namespace Tests\Feature\Story;

use App\Enums\Message\MessageType;
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
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->author = User::factory()->create([
        'name' => 'Jean-Paul Auteur',
        'display_name' => 'Jean-Paul',
        'handle' => 'jeanpaul',
    ]);

    $this->respondent = User::factory()->create([
        'name' => 'Claire Répondante',
        'display_name' => 'Claire',
        'handle' => 'claire',
    ]);

    $this->story = Story::create([
        'user_id' => $this->author->id,
        'title' => 'Story de Jean-Paul',
        'status' => 'active',
        'allow_replies' => true,
        'expires_at' => now()->addDay(),
    ]);

    $this->slide = StorySlide::create([
        'story_id' => $this->story->id,
        'media_type' => 'image',
        'media_path' => 'stories/slide1.jpg',
        'media_url' => 'https://bango.test/storage/stories/slide1.jpg',
        'thumbnail_url' => 'https://bango.test/storage/stories/thumb1.jpg',
        'caption' => 'Coucher de soleil à Kribi',
        'order' => 1,
    ]);
});

test('a single call to POST stories id reply produces exactly one message and one notification', function () {
    Notification::fake();
    Passport::actingAs($this->respondent);

    $idempotencyKey = (string) Str::uuid();

    $response = $this->postJson("/api/stories/{$this->story->id}/reply", [
        'slide_id' => $this->slide->id,
        'type' => 'text',
        'text' => 'Superbe prise Jean-Paul !',
        'idempotency_key' => $idempotencyKey,
    ]);

    $response->assertStatus(201);

    // Exactly 1 StoryReply in DB
    expect(StoryReply::where('story_id', $this->story->id)->count())->toBe(1);

    // Exactly 1 Conversation in DB
    $conversations = Conversation::all();
    expect($conversations)->toHaveCount(1);

    // Exactly 1 Message in the conversation
    $messages = Message::where('conversation_id', $conversations->first()->id)->get();
    expect($messages)->toHaveCount(1);
    expect($messages->first()->type)->toBe(MessageType::StoryReply);
    expect($messages->first()->body)->toBe('Superbe prise Jean-Paul !');

    // Exactly 1 NewStoryReplyNotification sent to story author
    Notification::assertSentToTimes($this->author, NewStoryReplyNotification::class, 1);
});

test('quick emoji reaction produces exactly one message and one notification', function () {
    Notification::fake();
    Passport::actingAs($this->respondent);

    $response = $this->postJson("/api/stories/{$this->story->id}/reply", [
        'slide_id' => $this->slide->id,
        'type' => 'reaction',
        'reaction' => '🔥',
    ]);

    $response->assertStatus(201);

    // Exactly 1 message and 1 notification
    $messages = Message::all();
    expect($messages)->toHaveCount(1);
    expect($messages->first()->body)->toBe('🔥');
    expect($messages->first()->type)->toBe(MessageType::StoryReply);

    Notification::assertSentToTimes($this->author, NewStoryReplyNotification::class, 1);
});

test('submitting with same idempotency_key is strictly idempotent and creates no duplicate', function () {
    Notification::fake();
    Passport::actingAs($this->respondent);

    $idempotencyKey = 'unique-key-'.uniqid();

    // First call
    $response1 = $this->postJson("/api/stories/{$this->story->id}/reply", [
        'slide_id' => $this->slide->id,
        'type' => 'text',
        'text' => 'Premier message',
        'idempotency_key' => $idempotencyKey,
    ]);
    $response1->assertStatus(201);

    // Second call with same idempotency key
    $response2 = $this->postJson("/api/stories/{$this->story->id}/reply", [
        'slide_id' => $this->slide->id,
        'type' => 'text',
        'text' => 'Premier message (retry)',
        'idempotency_key' => $idempotencyKey,
    ]);
    $response2->assertStatus(201);

    // Verify still exactly 1 StoryReply, 1 Message, 1 Notification
    expect(StoryReply::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
    expect(Message::count())->toBe(1);
    Notification::assertSentToTimes($this->author, NewStoryReplyNotification::class, 1);
});
