<?php

use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Search\PostSearchService;
use App\Services\Square\PostFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('enregistre ads_decided_at lors du choix publicitaire et le renvoie dans GET /profile/privacy', function () {
    $user = User::factory()->create([
        'privacy_settings' => [
            'ads_personalization' => true,
        ],
    ]);

    Passport::actingAs($user);

    // Initialement, ads_decided_at est null par défaut
    $getRes = getJson('/api/profile/privacy');
    $getRes->assertStatus(200)
        ->assertJsonPath('ads_personalization', true)
        ->assertJsonPath('ads_decided_at', null);

    // Mise à jour du ciblage publicitaire
    $putRes = putJson('/api/profile/privacy', [
        'ads_personalization' => false,
    ]);

    $putRes->assertStatus(200)
        ->assertJsonPath('ads_personalization', false);

    expect($putRes->json('ads_decided_at'))->not->toBeNull();

    // Relecture via GET
    $getResAfter = getJson('/api/profile/privacy');
    $getResAfter->assertStatus(200)
        ->assertJsonPath('ads_personalization', false)
        ->assertJsonPath('ads_decided_at', $putRes->json('ads_decided_at'));
});

it('renvoie can_comment, can_message et can_tag dans PublicProfileResource selon les réglages', function () {
    $target = User::factory()->create([
        'handle' => 'target.user',
        'privacy_settings' => [
            'commentPosts' => 'followers',
            'messageMe' => 'nobody',
            'tagMe' => 'followers',
        ],
    ]);

    $stranger = User::factory()->create();
    Passport::actingAs($stranger);

    $resStranger = getJson("/api/profile/public/{$target->id}");
    $resStranger->assertStatus(200)
        ->assertJsonPath('data.can_comment', false)
        ->assertJsonPath('data.can_message', false)
        ->assertJsonPath('data.can_tag', false);

    // Le follower a le droit de commenter et de taguer, mais toujours pas d'écrire car messageMe=nobody
    $follower = User::factory()->create();
    $follower->following()->attach($target->id);
    Passport::actingAs($follower);

    $resFollower = getJson("/api/profile/public/{$target->id}");
    $resFollower->assertStatus(200)
        ->assertJsonPath('data.can_comment', true)
        ->assertJsonPath('data.can_message', false)
        ->assertJsonPath('data.can_tag', true);
});

it('refuse (403) les commentaires sur une publication ou un reel selon commentPosts', function () {
    $author = User::factory()->create([
        'privacy_settings' => [
            'commentPosts' => 'followers',
        ],
    ]);

    $post = Post::factory()->create([
        'user_id' => $author->id,
        'audience' => 'public',
        'comments_by' => 'everyone',
    ]);

    $reel = Reel::factory()->create([
        'user_id' => $author->id,
        'status' => 'ready',
    ]);

    $stranger = User::factory()->create();
    Passport::actingAs($stranger);

    // Sur le post Square
    $postRes = postJson("/api/square-cr1/posts/{$post->id}/comments", [
        'body' => 'Super publication !',
    ]);
    $postRes->assertStatus(403);

    // Sur le Reel
    $reelRes = postJson("/api/reels/{$reel->id}/comments", [
        'body' => 'Super reel !',
    ]);
    $reelRes->assertStatus(403);

    // Maintenant un follower
    $follower = User::factory()->create();
    $follower->following()->attach($author->id);
    Passport::actingAs($follower);

    $postResFollower = postJson("/api/square-cr1/posts/{$post->id}/comments", [
        'body' => 'Bravo en tant que follower !',
    ]);
    $postResFollower->assertStatus(201);

    $reelResFollower = postJson("/api/reels/{$reel->id}/comments", [
        'body' => 'Bravo reel follower !',
    ]);
    $reelResFollower->assertStatus(201);
});

it('refuse (403) d\'ouvrir une conversation selon messageMe', function () {
    $author = User::factory()->create([
        'privacy_settings' => [
            'messageMe' => 'nobody',
        ],
    ]);

    $caller = User::factory()->create();
    Passport::actingAs($caller);

    $res = postJson('/api/messages/threads', [
        'peer_id' => $author->id,
    ]);
    $res->assertStatus(403);
});

it('refuse (403) les tags et mentions selon tagMe', function () {
    $author = User::factory()->create([
        'handle' => 'protected.user',
        'privacy_settings' => [
            'tagMe' => 'nobody',
        ],
    ]);

    $creator = User::factory()->create();
    Passport::actingAs($creator);

    // 1. Tag média
    $resTag = postJson('/api/square-cr1/posts', [
        'body' => 'Photo entre amis',
        'media' => [
            [
                'url' => '/storage/square/photo.jpg',
                'type' => 'photo',
            ],
        ],
        'tags' => [
            [
                'account_id' => $author->id,
                'media_index' => 0,
                'x' => 0.5,
                'y' => 0.5,
            ],
        ],
    ]);
    $resTag->assertStatus(403);

    // 2. Mention @handle dans la légende
    $resMention = postJson('/api/square-cr1/posts', [
        'body' => 'Dédicace à @protected.user !',
    ]);
    $resMention->assertStatus(403);
});

it('filtre les publications selon seePosts dans les feeds, profil et recherche', function () {
    $author = User::factory()->create([
        'privacy_settings' => [
            'seePosts' => 'followers',
        ],
    ]);

    $post = Post::factory()->create([
        'user_id' => $author->id,
        'body' => 'Contenu confidentiel aux abonnés',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    $stranger = User::factory()->create();
    $follower = User::factory()->create();
    $follower->following()->attach($author->id);

    $feedService = app(PostFeedService::class);
    $searchService = app(PostSearchService::class);

    // Dans le profil de l'auteur
    $feedStranger = $feedService->getFeed($stranger, ['author_id' => $author->id]);
    expect($feedStranger->items())->toBeEmpty();

    $feedFollower = $feedService->getFeed($follower, ['author_id' => $author->id]);
    expect($feedFollower->items())->toHaveCount(1);

    // Dans la recherche
    $searchStranger = $searchService->search('confidentiel', $stranger);
    expect($searchStranger->items())->toBeEmpty();

    $searchFollower = $searchService->search('confidentiel', $follower);
    expect($searchFollower->items())->toHaveCount(1);
});

it('gère les accusés de lecture et la réciprocité pour readReceipts', function () {
    $alice = User::factory()->create([
        'privacy_settings' => ['readReceipts' => true],
    ]);
    $bob = User::factory()->create([
        'privacy_settings' => ['readReceipts' => false],
    ]);

    $conversation = Conversation::factory()->create(['type' => 'direct']);
    $aliceMember = ConversationMember::create([
        'conversation_id' => $conversation->id,
        'user_id' => $alice->id,
        'role' => 'member',
    ]);
    $bobMember = ConversationMember::create([
        'conversation_id' => $conversation->id,
        'user_id' => $bob->id,
        'role' => 'member',
    ]);

    // Alice envoie un message
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $alice->id,
        'type' => 'text',
        'body' => 'Bonjour Bob',
        'created_at' => now()->subMinutes(5),
    ]);

    // Bob lit le message
    $bobMember->markAsRead($message->id);

    // Alice consulte le fil de messages : comme Bob a readReceipts: false, Alice ne doit pas voir 'read'
    Passport::actingAs($alice);
    $resAlice = getJson("/api/messages/threads/{$conversation->id}/messages");
    $resAlice->assertStatus(200);

    $msgDataAlice = collect($resAlice->json('data'))->firstWhere('id', $message->id);
    expect($msgDataAlice['is_read'])->toBeFalse()
        ->and($msgDataAlice['read_at'])->toBeNull();

    // Réciprocité : Bob envoie un message à Alice
    $messageFromBob = Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $bob->id,
        'type' => 'text',
        'body' => 'Bonjour Alice',
        'created_at' => now()->subMinutes(3),
    ]);

    // Alice lit le message de Bob (Alice a readReceipts: true)
    $aliceMember->markAsRead($messageFromBob->id);

    // Bob regarde le message : comme Bob a coupé ses propres readReceipts, par réciprocité il ne voit pas ceux d'Alice
    Passport::actingAs($bob);
    $resBob = getJson("/api/messages/threads/{$conversation->id}/messages");
    $resBob->assertStatus(200);

    $msgDataBob = collect($resBob->json('data'))->firstWhere('id', $messageFromBob->id);
    expect($msgDataBob['is_read'])->toBeFalse()
        ->and($msgDataBob['read_at'])->toBeNull();
});
