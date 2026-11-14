<?php

use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use App\Models\Story\StorySticker;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('SocialNotification generates translated title and body for story_like, story_question_answer and countdown_remind in FR and EN', function () {
    $authorFr = User::factory()->create(['name' => 'Jean-Paul', 'locale' => 'fr']);
    $authorEn = User::factory()->create(['name' => 'John', 'locale' => 'en']);
    $viewer = User::factory()->create(['name' => 'Sophie']);

    // 1. Story Like FR
    $notifLikeFr = new SocialNotification('story_like', $viewer, ['story_id' => 'story-123']);
    $dataLikeFr = $notifLikeFr->toArray($authorFr);
    expect($dataLikeFr['title'])->toBe('Nouveau j\'aime')
        ->and($dataLikeFr['message'])->toBe('Sophie a aimé votre story.')
        ->and($dataLikeFr['title'])->not->toContain('notifications.');

    // 2. Story Like EN
    $notifLikeEn = new SocialNotification('story_like', $viewer, ['story_id' => 'story-123']);
    $dataLikeEn = $notifLikeEn->toArray($authorEn);
    expect($dataLikeEn['title'])->toBe('New like')
        ->and($dataLikeEn['message'])->toBe('Sophie liked your story.')
        ->and($dataLikeEn['title'])->not->toContain('notifications.');

    // 3. Question Answer FR
    $notifAnswerFr = new SocialNotification('story_question_answer', $viewer, [
        'story_id' => 'story-123',
        'text' => 'Douala',
    ]);
    $dataAnswerFr = $notifAnswerFr->toArray($authorFr);
    expect($dataAnswerFr['title'])->toBe('Réponse à votre question')
        ->and($dataAnswerFr['message'])->toBe('Sophie a répondu à votre question : "Douala"')
        ->and($dataAnswerFr['title'])->not->toContain('notifications.');

    // 4. Question Answer EN
    $notifAnswerEn = new SocialNotification('story_question_answer', $viewer, [
        'story_id' => 'story-123',
        'text' => 'Douala',
    ]);
    $dataAnswerEn = $notifAnswerEn->toArray($authorEn);
    expect($dataAnswerEn['title'])->toBe('Answer to your question')
        ->and($dataAnswerEn['message'])->toBe('Sophie answered your question: "Douala"')
        ->and($dataAnswerEn['title'])->not->toContain('notifications.');

    // 5. Countdown Remind FR
    $notifRemindFr = new SocialNotification('countdown_remind', $authorFr, ['story_id' => 'story-123']);
    $dataRemindFr = $notifRemindFr->toArray($authorFr);
    expect($dataRemindFr['title'])->toBe('Rappel d\'événement')
        ->and($dataRemindFr['message'])->toBe('Rappel activé pour le compte à rebours.')
        ->and($dataRemindFr['title'])->not->toContain('notifications.');

    // 6. Countdown Remind EN
    $notifRemindEn = new SocialNotification('countdown_remind', $authorEn, ['story_id' => 'story-123']);
    $dataRemindEn = $notifRemindEn->toArray($authorEn);
    expect($dataRemindEn['title'])->toBe('Event reminder')
        ->and($dataRemindEn['message'])->toBe('Reminder set for the countdown.')
        ->and($dataRemindEn['title'])->not->toContain('notifications.');
});

test('liking a story sends story_like notification and unliking then reliking does not renotify', function () {
    $author = User::factory()->create(['name' => 'Paul', 'locale' => 'fr']);
    $viewer = User::factory()->create(['name' => 'Marc', 'locale' => 'fr']);

    $story = Story::factory()->create([
        'user_id' => $author->id,
        'published_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    StorySlide::create([
        'story_id' => $story->id,
        'media_type' => 'image',
        'media_path' => 'stories/slide1.jpg',
        'media_url' => 'http://localhost/storage/stories/slide1.jpg',
        'order' => 0,
    ]);

    Passport::actingAs($viewer);

    // 1. First like
    $likeRes1 = $this->postJson("/api/stories/{$story->id}/like");
    $likeRes1->assertOk();

    // Verify 1 notification sent to author of type story_like
    expect($author->notifications()->count())->toBe(1);
    $notif1 = $author->notifications()->first();
    expect($notif1->data['type'])->toBe('story_like')
        ->and($notif1->data['message'])->toBe('Marc a aimé votre story.')
        ->and($notif1->data['title'])->toBe('Nouveau j\'aime');

    // 2. Unlike
    $unlikeRes = $this->deleteJson("/api/stories/{$story->id}/like");
    $unlikeRes->assertOk();

    // 3. Relike the same story
    $likeRes2 = $this->postJson("/api/stories/{$story->id}/like");
    $likeRes2->assertOk();

    // Verify notification count is still 1 (no duplicate notification)
    expect($author->notifications()->count())->toBe(1);
});

test('answering a story question sticker sends story_question_answer notification with translated text', function () {
    $author = User::factory()->create(['name' => 'Alice', 'locale' => 'fr']);
    $viewer = User::factory()->create(['name' => 'Bob', 'locale' => 'fr']);

    $story = Story::factory()->create([
        'user_id' => $author->id,
        'published_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    $slide = StorySlide::create([
        'story_id' => $story->id,
        'media_type' => 'image',
        'media_path' => 'stories/slide1.jpg',
        'media_url' => 'http://localhost/storage/stories/slide1.jpg',
        'order' => 0,
    ]);

    $questionSticker = StorySticker::create([
        'story_slide_id' => $slide->id,
        'type' => 'question',
        'x' => 0.5,
        'y' => 0.5,
        'scale' => 1,
        'rotation' => 0,
        'data' => ['question' => 'Votre avis ?'],
    ]);

    Passport::actingAs($viewer);

    $res = $this->postJson("/api/stories/stickers/{$questionSticker->id}/answer", [
        'answer' => 'Excellent !',
    ]);
    $res->assertOk();

    $notif = $author->notifications()->where('data->type', 'story_question_answer')->first();
    expect($notif)->not->toBeNull()
        ->and($notif->data['title'])->toBe('Réponse à votre question')
        ->and($notif->data['message'])->toBe('Bob a répondu à votre question : "Excellent !"');
});
