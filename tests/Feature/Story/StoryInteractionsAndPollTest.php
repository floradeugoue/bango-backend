<?php

use App\Enums\Story\StoryStickerType;
use App\Jobs\Story\SendCountdownReminderJob;
use App\Models\Story\Story;
use App\Models\Story\StoryPollVote;
use App\Models\Story\StorySlide;
use App\Models\Story\StorySticker;
use App\Models\Story\StoryView;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->author = User::factory()->create();
    $this->viewer = User::factory()->create();
    Passport::actingAs($this->viewer);
});

test('l enregistrement d une vue incrémente le compteur avec anonymisation IP', function () {
    $story = Story::factory()->create([
        'user_id' => $this->author->id,
        'views_count' => 0,
    ]);

    $res1 = $this->postJson("/api/stories/{$story->id}/view", [], ['REMOTE_ADDR' => '192.168.1.100']);

    $res1->assertStatus(200)
        ->assertJsonPath('data.viewed', true)
        ->assertJsonPath('data.views_count', 1);

    // Vérification que l'adresse IP est bien hachée et non stockée en clair
    $view = StoryView::where('story_id', $story->id)->first();
    expect($view)->not->toBeNull()
        ->and($view->ip_address)->not->toBe('192.168.1.100');

    // Deuxième consultation par le même utilisateur : dédupliquée
    $res2 = $this->postJson("/api/stories/{$story->id}/view", [], ['REMOTE_ADDR' => '192.168.1.100']);

    $res2->assertStatus(200)
        ->assertJsonPath('data.viewed', false)
        ->assertJsonPath('data.views_count', 1);

    expect($story->fresh()->views_count)->toBe(1);
});

test('seul le propriétaire peut consulter la liste de ses spectateurs', function () {
    $story = Story::factory()->create([
        'user_id' => $this->author->id,
    ]);

    // Spectateur tiers tente de voir les spectateurs -> 403 Forbidden
    $this->getJson("/api/stories/{$story->id}/viewers")->assertStatus(403);

    // Propriétaire connecté
    Passport::actingAs($this->author);
    $res = $this->getJson("/api/stories/{$story->id}/viewers");
    $res->assertStatus(200);
});

test('un utilisateur peut voter sur un sondage de Story et les statistiques se mettent à jour', function () {
    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);
    $sticker = StorySticker::factory()->poll('Aimez-vous le Ndolè ?', ['Oui', 'Non'])->create([
        'story_slide_id' => $slide->id,
    ]);

    $response = $this->postJson("/api/stories/{$slide->id}/poll/{$sticker->id}/vote", [
        'option_index' => 0,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.votes_count', 1)
        ->assertJsonPath('data.results.0', 1)
        ->assertJsonPath('data.results.1', 0);

    $this->assertDatabaseHas('story_poll_votes', [
        'sticker_id' => $sticker->id,
        'user_id' => $this->viewer->id,
        'option_index' => 0,
    ]);
});

test('un double vote sur le même sondage est rejeté avec un conflit 409', function () {
    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);
    $sticker = StorySticker::factory()->poll()->create(['story_slide_id' => $slide->id]);

    // Premier vote réussi
    $this->postJson("/api/stories/{$slide->id}/poll/{$sticker->id}/vote", ['option_index' => 0])
        ->assertStatus(200);

    // Deuxième vote refusé
    $res2 = $this->postJson("/api/stories/{$slide->id}/poll/{$sticker->id}/vote", ['option_index' => 1]);
    $res2->assertStatus(409);
});

test('le vote sur une Story expirée est rejeté avec 410', function () {
    $story = Story::factory()->expired()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);
    $sticker = StorySticker::factory()->poll()->create(['story_slide_id' => $slide->id]);

    $this->postJson("/api/stories/{$slide->id}/poll/{$sticker->id}/vote", ['option_index' => 0])
        ->assertStatus(410);
});

test('le vote sur un sticker appartenant à une autre diapositive est rejeté avec 404', function () {
    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide1 = StorySlide::factory()->create(['story_id' => $story->id]);
    $slide2 = StorySlide::factory()->create(['story_id' => $story->id]);
    $sticker = StorySticker::factory()->poll()->create(['story_slide_id' => $slide1->id]);

    // Tentative de voter sur slide2 avec le sticker de slide1
    $this->postJson("/api/stories/{$slide2->id}/poll/{$sticker->id}/vote", ['option_index' => 0])
        ->assertStatus(404);
});

test('le vote avec un index d option invalide est rejeté avec 422', function () {
    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);
    $sticker = StorySticker::factory()->poll('Question ?', ['Choix 0', 'Choix 1'])->create(['story_slide_id' => $slide->id]);

    $this->postJson("/api/stories/{$slide->id}/poll/{$sticker->id}/vote", ['option_index' => 5])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['option_index']);
});

test('le propriétaire peut consulter les spectateurs et voir les votes de sondage associés (CR-35)', function () {
    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);
    $sticker = StorySticker::factory()->poll('Question ?', ['Choix 0', 'Choix 1'])->create(['story_slide_id' => $slide->id]);

    StoryView::create([
        'story_id' => $story->id,
        'story_slide_id' => $slide->id,
        'viewer_id' => $this->viewer->id,
        'ip_address' => hash('sha256', '127.0.0.1'),
        'viewed_at' => now(),
    ]);

    StoryPollVote::create([
        'story_slide_id' => $slide->id,
        'sticker_id' => $sticker->id,
        'user_id' => $this->viewer->id,
        'option_index' => 1,
    ]);

    Passport::actingAs($this->author);
    $response = $this->getJson("/api/stories/{$story->id}/viewers");

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->viewer->id)
        ->assertJsonPath('data.0.has_voted', true)
        ->assertJsonPath('data.0.vote.slide_id', (string) $slide->id)
        ->assertJsonPath('data.0.vote.sticker_id', (string) $sticker->id)
        ->assertJsonPath('data.0.vote.option_index', 1);
});

test('POST /api/stories/stickers/{id}/remind programme un job différé à until et n envoie rien au moment du toucher', function () {
    Queue::fake();
    Notification::fake();

    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);

    $futureUntil = Carbon::now()->addHours(6)->toIso8601String();
    $sticker = StorySticker::factory()->create([
        'story_slide_id' => $slide->id,
        'type' => StoryStickerType::Countdown,
        'data' => [
            'title' => 'Lancement produit Bango',
            'until' => $futureUntil,
        ],
    ]);

    // Activation du rappel
    $response = $this->postJson("/api/stories/stickers/{$sticker->id}/remind");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.message', 'Rappel de compte à rebours activé.');

    // 1. Enregistrement en base
    $this->assertDatabaseHas('story_sticker_answers', [
        'sticker_id' => $sticker->id,
        'user_id' => $this->viewer->id,
        'type' => 'countdown_reminder',
    ]);

    // 2. AUCUNE notification n'est envoyée immédiatement au moment du toucher
    Notification::assertNothingSent();

    // 3. Un job différé a été programmé pour l'échéance 'until'
    Queue::assertPushed(SendCountdownReminderJob::class, function ($job) use ($sticker, $futureUntil) {
        return $job->stickerId === $sticker->id
            && $job->userId === $this->viewer->id
            && $job->delay !== null
            && $job->delay->toIso8601String() === Carbon::parse($futureUntil)->toIso8601String();
    });
});

test('DELETE /api/stories/stickers/{id}/remind annule le rappel et empêche l envoi de la notification', function () {
    Queue::fake();
    Notification::fake();

    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);

    $futureUntil = Carbon::now()->addHours(2)->toIso8601String();
    $sticker = StorySticker::factory()->create([
        'story_slide_id' => $slide->id,
        'type' => StoryStickerType::Countdown,
        'data' => [
            'title' => 'Grand concert',
            'until' => $futureUntil,
        ],
    ]);

    // Activer le rappel
    $this->postJson("/api/stories/stickers/{$sticker->id}/remind")->assertOk();

    $this->assertDatabaseHas('story_sticker_answers', [
        'sticker_id' => $sticker->id,
        'user_id' => $this->viewer->id,
        'type' => 'countdown_reminder',
    ]);

    // Annuler le rappel
    $deleteRes = $this->deleteJson("/api/stories/stickers/{$sticker->id}/remind");
    $deleteRes->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.message', 'Rappel de compte à rebours annulé.');

    $this->assertDatabaseMissing('story_sticker_answers', [
        'sticker_id' => $sticker->id,
        'user_id' => $this->viewer->id,
        'type' => 'countdown_reminder',
    ]);

    // Exécution du job : comme le rappel a été annulé, aucune notification ne doit être envoyée
    $job = new SendCountdownReminderJob($sticker->id, $this->viewer->id);
    $job->handle();

    Notification::assertNothingSent();
});

test('SendCountdownReminderJob envoie la notification countdown_remind si le rappel est toujours actif à l échéance', function () {
    Queue::fake();
    Notification::fake();

    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);

    $sticker = StorySticker::factory()->create([
        'story_slide_id' => $slide->id,
        'type' => StoryStickerType::Countdown,
        'data' => [
            'title' => 'Vente flash',
            'until' => Carbon::now()->addHour()->toIso8601String(),
        ],
    ]);

    // Activer le rappel
    $this->postJson("/api/stories/stickers/{$sticker->id}/remind")->assertOk();

    // Exécution du job à échéance
    $job = new SendCountdownReminderJob($sticker->id, $this->viewer->id);
    $job->handle();

    Notification::assertSentTo($this->viewer, SocialNotification::class, function ($notification) use ($story, $sticker) {
        return $notification->type === 'countdown_remind'
            && $notification->metadata['story_id'] === (string) $story->id
            && $notification->metadata['sticker_id'] === (string) $sticker->id;
    });
});

test('répondre à une question et demander un rappel via les routes longues et courtes fonctionne', function () {
    $story = Story::factory()->create(['user_id' => $this->author->id]);
    $slide = StorySlide::factory()->create(['story_id' => $story->id]);

    $questionSticker = StorySticker::create([
        'story_slide_id' => $slide->id,
        'type' => 'question',
        'x' => 0.5,
        'y' => 0.5,
        'scale' => 1,
        'rotation' => 0,
        'data' => ['question' => 'Quel quartier préférez-vous ?'],
    ]);

    $countdownSticker = StorySticker::create([
        'story_slide_id' => $slide->id,
        'type' => 'countdown',
        'x' => 0.5,
        'y' => 0.5,
        'scale' => 1,
        'rotation' => 0,
        'data' => ['title' => 'Lancement officiel', 'end_date' => now()->addDays(5)->toIso8601String()],
    ]);

    // 1. Long route for question answer
    $longAnswerRes = $this->postJson("/api/stories/{$slide->id}/stickers/{$questionSticker->id}/answer", [
        'answer' => 'Bonapriso',
    ]);
    $longAnswerRes->assertOk()
        ->assertJsonPath('success', true);

    // 2. Short route for question answer
    $shortAnswerRes = $this->postJson("/api/stories/stickers/{$questionSticker->id}/answer", [
        'answer' => 'Akwa',
    ]);
    $shortAnswerRes->assertOk()
        ->assertJsonPath('success', true);

    // 3. Long route for countdown reminder
    $longRemindRes = $this->postJson("/api/stories/{$slide->id}/stickers/{$countdownSticker->id}/remind");
    $longRemindRes->assertOk()
        ->assertJsonPath('success', true);

    // 4. Short route for countdown reminder
    $shortRemindRes = $this->postJson("/api/stories/stickers/{$countdownSticker->id}/remind");
    $shortRemindRes->assertOk()
        ->assertJsonPath('success', true);
});
