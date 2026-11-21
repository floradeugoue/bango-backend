<?php

namespace Tests\Feature\Story;

use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);
    Storage::fake('public');
});

test('story publication stores rendered capture in thumbnail_url and provides it in story_preview_card on reply', function () {
    $author = User::factory()->create([
        'name' => 'Auteur Story',
        'handle' => 'auteur_story',
    ]);
    $respondent = User::factory()->create([
        'name' => 'Répondant Story',
        'handle' => 'repondant_story',
    ]);

    Passport::actingAs($author);

    // Fond uni (aplat rouge)
    $backgroundFile = UploadedFile::fake()->image('background_red.jpg', 600, 800);
    // Capture complète de la story telle qu'elle s'affiche (fond + texte + question/stickers)
    $renderedCapture = UploadedFile::fake()->image('rendered_capture.png', 600, 800);

    // 1. Publication de la story avec le fond dans file et la capture dans thumbnail (ou capture / preview)
    $postResponse = $this->postJson('/api/stories', [
        'file' => $backgroundFile,
        'thumbnail' => $renderedCapture,
        'caption' => 'Posez-moi une question !',
    ]);

    $postResponse->assertStatus(201);
    $storyId = $postResponse->json('data.id') ?? $postResponse->json('id');

    $slide = StorySlide::where('story_id', $storyId)->firstOrFail();

    // La vignette thumbnail_url doit être la capture complète enregistrée et non l'image de fond brute
    expect($slide->thumbnail_url)->not->toBeNull()
        ->and($slide->thumbnail_url)->not->toBe($slide->media_url);

    // 2. Le répondant répond à la story
    Passport::actingAs($respondent);

    $replyResponse = $this->postJson("/api/stories/{$storyId}/reply", [
        'type' => 'text',
        'text' => 'Voici ma réponse à ta question !',
    ]);

    $replyResponse->assertStatus(201);

    // La carte d'aperçu de la story doit contenir la capture complète dans thumbnail_url
    $previewCard = $replyResponse->json('data.story_preview_card') ?? $replyResponse->json('story_preview_card');

    expect($previewCard)->not->toBeNull()
        ->and($previewCard['thumbnail_url'])->toBe($slide->thumbnail_url)
        ->and($previewCard['thumbnail_url'])->not->toBe($slide->media_url);
});

test('story publication accepts capture or preview aliases and thumbnail_url string', function () {
    $author = User::factory()->create();

    Passport::actingAs($author);

    $bg = UploadedFile::fake()->image('bg.jpg', 100, 100);
    $captureUrl = 'https://cdn.bangoapp.com/stories/rendered_text_story_123.jpg';

    $response = $this->postJson('/api/stories', [
        'file' => $bg,
        'thumbnail_url' => $captureUrl,
    ]);

    $response->assertStatus(201);
    $storyId = $response->json('data.id') ?? $response->json('id');
    $slide = StorySlide::where('story_id', $storyId)->firstOrFail();

    expect($slide->thumbnail_url)->toBe($captureUrl);
});
