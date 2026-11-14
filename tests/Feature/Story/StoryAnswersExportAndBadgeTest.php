<?php

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageSector;
use App\Enums\Story\StoryMediaType;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use App\Models\Story\StorySticker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->author = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);
    $this->viewer = User::factory()->create([
        'badge' => 'agent',
    ]);
});

test('l auteur peut relire les reponses a sa question avec personne et heure paginees', function () {
    Passport::actingAs($this->author);

    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);

    $sticker = StorySticker::create([
        'story_slide_id' => $slide->id,
        'type' => 'question',
        'x' => 0.5,
        'y' => 0.5,
        'scale' => 1,
        'rotation' => 0,
        'data' => ['question' => 'Quel quartier préférez-vous ?'],
    ]);

    $respondent = User::factory()->create([
        'name' => 'Awa Ndiaye',
        'display_name' => 'Awa Ndiaye',
        'badge' => 'landlord',
    ]);

    // Enregistrement d'une réponse
    DB::table('story_sticker_answers')->insert([
        'id' => (string) Str::uuid(),
        'story_id' => $story->id,
        'slide_id' => $slide->id,
        'sticker_id' => $sticker->id,
        'user_id' => $respondent->id,
        'type' => 'question',
        'answer' => 'Almadies sans hésiter !',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    // 1. L'auteur consulte les réponses
    $response = $this->getJson("/api/stories/{$story->id}/answers");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'story_id',
                    'slide_id',
                    'sticker_id',
                    'type',
                    'answer',
                    'user' => [
                        'id',
                        'name',
                        'display_name',
                        'avatar_url',
                        'badge',
                    ],
                    'sticker' => [
                        'id',
                        'type',
                        'question',
                    ],
                    'created_at',
                    'answered_at',
                ],
            ],
            'links',
            'meta',
        ]);

    $data = $response->json('data');
    expect(count($data))->toBe(1);
    expect($data[0]['answer'])->toBe('Almadies sans hésiter !');
    expect($data[0]['user']['id'])->toBe($respondent->id);
    expect($data[0]['user']['badge'])->toBe('landlord');
    expect($data[0]['sticker']['question'])->toBe('Quel quartier préférez-vous ?');
    expect($data[0]['created_at'])->not->toBeNull();

    // 2. Un tiers non propriétaire reçoit une 403
    Passport::actingAs($this->viewer);
    $this->getJson("/api/stories/{$story->id}/answers")->assertStatus(403);
});

test('une page propriétaire de story peut relire les réponses à sa question', function () {
    $pageOwner = User::factory()->create(['badge' => 'none']);
    $page = Page::create([
        'owner_id' => $pageOwner->id,
        'name' => 'Immo Dakar',
        'handle' => 'immo_dakar_'.uniqid(),
        'sector' => PageSector::RealEstateAgency->value,
        'certification_status' => PageCertificationStatus::Approved->value,
    ]);

    $story = Story::factory()->create([
        'user_id' => $pageOwner->id,
        'page_id' => $page->id,
    ]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);
    $sticker = StorySticker::create([
        'story_slide_id' => $slide->id,
        'type' => 'question',
        'x' => 0.5,
        'y' => 0.5,
        'scale' => 1,
        'rotation' => 0,
        'data' => ['question' => 'Votre budget moyen ?'],
    ]);

    DB::table('story_sticker_answers')->insert([
        'id' => (string) Str::uuid(),
        'story_id' => $story->id,
        'slide_id' => $slide->id,
        'sticker_id' => $sticker->id,
        'user_id' => $this->viewer->id,
        'type' => 'question',
        'answer' => '500 000 FCFA',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Le propriétaire de la page a accès
    Passport::actingAs($pageOwner);
    $response = $this->getJson("/api/stories/{$story->id}/answers");
    $response->assertStatus(200);
    expect(count($response->json('data')))->toBe(1);

    // Un membre de la page a également accès
    $member = User::factory()->create();
    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $member->id,
        'role' => PageRole::Editor->value,
        'status' => PageMemberStatus::Accepted->value,
    ]);
    Passport::actingAs($member);
    $this->getJson("/api/stories/{$story->id}/answers")->assertStatus(200);
});

test('export d une story sans montage video renvoie status unavailable plutôt que ready', function () {
    Passport::actingAs($this->author);

    $story = Story::factory()->create(['user_id' => $this->author->id]);

    // Diapositive photo avec son
    StorySlide::factory()->create([
        'story_id' => $story->id,
        'media_type' => StoryMediaType::Image,
        'media_url' => 'https://cdn.bango.app/stories/photo1.jpg',
        'sound_metadata' => ['title' => 'Chanson Youssou Ndour'],
    ]);

    $response = $this->getJson("/api/stories/{$story->id}/export");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'story_id' => (string) $story->id,
                'status' => 'unavailable',
                'export_url' => null,
                'has_sound' => true,
            ],
        ]);
});

test('export d une story renvoie ready si une vraie video montee existe', function () {
    Passport::actingAs($this->author);

    // Story avec métadonnées vidéo montée
    $story = Story::factory()->create([
        'user_id' => $this->author->id,
        'metadata' => [
            'export_video_url' => 'https://cdn.bango.app/stories/assembled_video.mp4',
        ],
    ]);

    StorySlide::factory()->create([
        'story_id' => $story->id,
        'media_type' => StoryMediaType::Image,
        'media_url' => 'https://cdn.bango.app/stories/photo1.jpg',
    ]);

    $response = $this->getJson("/api/stories/{$story->id}/export");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'ready',
                'export_url' => 'https://cdn.bango.app/stories/assembled_video.mp4',
            ],
        ]);
});

test('le rail des stories et StoryResource incluent le badge de l auteur', function () {
    Passport::actingAs($this->viewer);

    // Auteur avec badge bailleur
    $story = Story::factory()->create([
        'user_id' => $this->author->id,
        'published_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);
    StorySlide::factory()->create([
        'story_id' => $story->id,
        'media_type' => StoryMediaType::Image,
        'media_url' => 'https://cdn.bango.app/stories/slide.jpg',
    ]);

    // 1. Vérification dans GET /api/stories/rail
    $railResponse = $this->getJson('/api/stories/rail');
    $railResponse->assertStatus(200);

    $items = $railResponse->json('data') ?? $railResponse->json();
    $authorItem = collect($items)->firstWhere('user.id', $this->author->id);

    expect($authorItem)->not->toBeNull();
    expect($authorItem['user']['badge'])->toBe('landlord');

    // 2. Vérification dans GET /api/stories/{id} (StoryResource)
    $storyResponse = $this->getJson("/api/stories/{$story->id}");
    $storyResponse->assertStatus(200);

    expect($storyResponse->json('data.author.badge'))->toBe('landlord');
    expect($storyResponse->json('data.user.badge'))->toBe('landlord');
    expect($storyResponse->json('data.author_badge'))->toBe('landlord');
});
