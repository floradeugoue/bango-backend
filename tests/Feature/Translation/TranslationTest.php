<?php

use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\User;
use App\Services\Translation\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

test('detects language accurately for french, english and wolof', function () {
    /** @var TranslationService $service */
    $service = app(TranslationService::class);

    expect($service->detectLanguage('Bonjour à tous, bienvenue sur Bango !'))->toBe('fr')
        ->and($service->detectLanguage('Hello everyone, this is an awesome new feature'))->toBe('en')
        ->and($service->detectLanguage('Nanga def jerejef sama xarit'))->toBe('wo');
});

test('post resource and comments include language attribute', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'body' => 'Welcome to the party, this is so cool!',
        'language' => 'en',
    ]);

    $comment = PostComment::create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Jerejef waaw baaxna',
        'language' => 'wo',
    ]);

    $postResponse = $this->actingAs($user, 'api')->getJson("/api/posts/{$post->id}");
    $postResponse->assertStatus(200);
    expect($postResponse->json('data.language'))->toBe('en');

    $commentResponse = $this->actingAs($user, 'api')->getJson("/api/posts/{$post->id}/comments");
    $commentResponse->assertStatus(200);
    expect($commentResponse->json('data.0.language'))->toBe('wo');
});

test('reel resource and reel comments include language attribute', function () {
    $user = User::factory()->create();

    $reel = Reel::create([
        'user_id' => $user->id,
        'caption' => 'Magnifique journée à Dakar',
        'language' => 'fr',
        'status' => 'ready',
    ]);

    $reelComment = ReelComment::create([
        'reel_id' => $reel->id,
        'user_id' => $user->id,
        'body' => 'Great video keep going!',
        'language' => 'en',
    ]);

    $reelResponse = $this->actingAs($user, 'api')->getJson("/api/reels/{$reel->id}");
    $reelResponse->assertStatus(200);
    expect($reelResponse->json('data.language'))->toBe('fr');

    $reelCommentResponse = $this->actingAs($user, 'api')->getJson("/api/reels/{$reel->id}/comments");
    $reelCommentResponse->assertStatus(200);
    expect($reelCommentResponse->json('data.0.language'))->toBe('en');
});

test('POST /api/translate validates inputs', function () {
    $response = $this->postJson('/api/translate', []);
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['type', 'id', 'target']);

    $responseInvalid = $this->postJson('/api/translate', [
        'type' => 'unknown_type',
        'id' => 1,
        'target' => 'de',
    ]);
    $responseInvalid->assertStatus(422)
        ->assertJsonValidationErrors(['type', 'target']);
});

test('POST /api/translate translates post, reel and comments with caching', function () {
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $user->id,
        'body' => 'Hello world, nice to meet you all.',
        'language' => 'en',
    ]);

    // Mock Google Translate HTTP call
    Http::fake([
        'https://translation.googleapis.com/language/translate/v2*' => Http::response([
            'data' => [
                'translations' => [
                    [
                        'translatedText' => 'Bonjour le monde, ravi de vous rencontrer tous.',
                        'detectedSourceLanguage' => 'en',
                    ],
                ],
            ],
        ], 200),
    ]);

    config(['services.translation.driver' => 'google']);
    config(['services.translation.google_api_key' => 'fake-key']);

    $response = $this->postJson('/api/translate', [
        'type' => 'post',
        'id' => $post->id,
        'target' => 'fr',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'text' => 'Bonjour le monde, ravi de vous rencontrer tous.',
            'source_language' => 'en',
        ]);

    $initialCallCount = Http::recorded()->count();
    expect($initialCallCount)->toBeGreaterThanOrEqual(1);

    // Second call with same text and target must use cache and NOT trigger any new HTTP call
    $responseCached = $this->postJson('/api/translate', [
        'type' => 'post',
        'id' => $post->id,
        'target' => 'fr',
    ]);

    $responseCached->assertStatus(200)
        ->assertJson([
            'success' => true,
            'text' => 'Bonjour le monde, ravi de vous rencontrer tous.',
            'source_language' => 'en',
        ]);

    expect(Http::recorded()->count())->toBe($initialCallCount);
});

test('POST /api/translate translates reel comment', function () {
    $user = User::factory()->create();

    $reel = Reel::create([
        'user_id' => $user->id,
        'caption' => 'Test reel',
        'status' => 'ready',
    ]);

    $reelComment = ReelComment::create([
        'reel_id' => $reel->id,
        'user_id' => $user->id,
        'body' => 'Ceci est un excellent contenu',
        'language' => 'fr',
    ]);

    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    [
                        'translatedText' => 'This is great content',
                        'detectedSourceLanguage' => 'fr',
                    ],
                ],
            ],
        ], 200),
    ]);

    config(['services.translation.driver' => 'google']);
    config(['services.translation.google_api_key' => 'fake-key']);

    $response = $this->postJson('/api/translate', [
        'type' => 'reel_comment',
        'id' => $reelComment->id,
        'target' => 'en',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'text' => 'This is great content',
            'source_language' => 'fr',
            'data' => [
                'translated' => 'This is great content',
                'source' => 'fr',
            ],
        ]);
});

test('POST /api/translate supports direct text and returns data.translated and data.source', function () {
    Http::fake([
        'translation.googleapis.com/*' => Http::response([
            'data' => [
                'translations' => [
                    [
                        'translatedText' => 'Bonjour, bienvenue sur Bango',
                        'detectedSourceLanguage' => 'en',
                    ],
                ],
            ],
        ], 200),
    ]);

    config(['services.translation.driver' => 'google']);
    config(['services.translation.google_api_key' => 'fake-key']);

    $response = $this->postJson('/api/translate', [
        'text' => 'Hello, welcome to Bango',
        'target' => 'fr',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.translated', 'Bonjour, bienvenue sur Bango')
        ->assertJsonPath('data.source', 'en')
        ->assertJsonPath('text', 'Bonjour, bienvenue sur Bango')
        ->assertJsonPath('source_language', 'en');
});
