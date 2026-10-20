<?php

use App\Jobs\Notification\SendPushNotificationJob;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Square\PostCommentService;
use App\Services\Square\PostLikeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();

    $this->author = User::factory()->create([
        'name' => 'Alice Doe',
        'handle' => 'alicedoe',
        'locale' => 'fr',
    ]);

    $this->interactor = User::factory()->create([
        'name' => 'Bob Smith',
        'handle' => 'bobsmith',
        'locale' => 'en',
    ]);

    $this->mentioned = User::factory()->create([
        'name' => 'Charlie Mention',
        'handle' => 'charliem',
        'locale' => 'en',
    ]);

    $this->post = Post::factory()->create([
        'user_id' => $this->author->id,
        'body' => 'Hello World',
    ]);
});

test('liking a post notifies author and dispatches push job', function (): void {
    app(PostLikeService::class)->like($this->post, $this->interactor);

    expect($this->author->notifications()->count())->toBe(1);
    $notification = $this->author->notifications()->first();

    expect($notification->data['type'])->toBe('like')
        ->and($notification->data['actor_id'])->toBe($this->interactor->id)
        ->and($notification->data['title'])->toBe('Nouveau j\'aime');

    Queue::assertPushed(SendPushNotificationJob::class, function ($job) {
        return $job->user->id === $this->author->id && $job->title === 'Nouveau j\'aime';
    });
});

test('commenting on post and replying to comment notifies respective authors and handles mentions', function (): void {
    $commentService = app(PostCommentService::class);

    // 1. Commentaire avec mention @charliem
    $comment = $commentService->createComment($this->post, $this->interactor, 'Superbe @charliem !');

    // L'auteur du post reçoit la notification de commentaire
    $authorNotif = $this->author->notifications()->where('data->type', 'comment')->first();
    expect($authorNotif)->not->toBeNull();

    // L'utilisateur mentionné reçoit la notification de mention (en anglais car locale='en')
    $mentionNotif = $this->mentioned->notifications()->where('data->type', 'mention')->first();
    expect($mentionNotif)->not->toBeNull()
        ->and($mentionNotif->data['title'])->toBe('Mention');

    // 2. Réponse au commentaire par l'auteur du post
    $commentService->createComment($this->post, $this->author, 'Merci Bob !', $comment->id);

    // Bob reçoit la notification de réponse (en anglais)
    $replyNotif = $this->interactor->notifications()->where('data->type', 'reply')->first();
    expect($replyNotif)->not->toBeNull()
        ->and($replyNotif->data['title'])->toBe('New reply');
});

test('following public and private accounts triggers follow and follow_request notifications', function (): void {
    $privateUser = User::factory()->create([
        'is_private' => true,
        'locale' => 'en',
    ]);

    Passport::actingAs($this->interactor);

    // 1. Follow private account -> follow_request
    $resp1 = $this->postJson("/api/profile/{$privateUser->id}/follow");
    $resp1->assertOk();
    expect($resp1->json('status'))->toBe('requested');

    $reqNotif = $privateUser->notifications()->where('data->type', 'follow_request')->first();
    expect($reqNotif)->not->toBeNull()
        ->and($reqNotif->data['title'])->toBe('Follow request');

    // 2. Follow public account -> follow
    $resp2 = $this->postJson("/api/profile/{$this->author->id}/follow");
    $resp2->assertOk();
    expect($resp2->json('status'))->toBe('followed');

    $followNotif = $this->author->notifications()->where('data->type', 'follow')->first();
    expect($followNotif)->not->toBeNull()
        ->and($followNotif->data['title'])->toBe('Nouvel abonné');
});
