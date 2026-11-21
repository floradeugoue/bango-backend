<?php

use App\Enums\Support\ReportReason;
use App\Models\Auth\Role;
use App\Models\Square\Post;
use App\Models\Story\Story;
use App\Models\Support\Report;
use App\Models\User;
use App\Notifications\Support\ChildSafetyAlertNotification;
use App\Services\Admin\ModerationQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);

    $this->reporter = User::factory()->create(['name' => 'Alice Vigilante']);
    $this->targetUser = User::factory()->create(['name' => 'Bob Auteur']);
    $this->post = Post::factory()->create(['user_id' => $this->targetUser->id]);

    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $modRole = Role::firstOrCreate(['slug' => 'moderation'], ['name' => 'Modérateur']);

    $this->adminUser = User::factory()->create(['role_id' => $adminRole->id]);
    $this->modUser = User::factory()->create(['role_id' => $modRole->id]);
});

test('POST /api/reports accepts child_safety and sexual_content reasons', function () {
    Passport::actingAs($this->reporter);

    // 1. child_safety
    $res1 = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $this->post->id,
        'reason' => 'child_safety',
        'details' => 'Mineur en danger immédiat.',
    ])->assertStatus(201);

    expect($res1->json('data.reason.value'))->toBe('child_safety');

    $report1 = Report::find($res1->json('data.id'));
    expect($report1->priority)->toBe('urgent');
    expect($report1->reason)->toBe(ReportReason::ChildSafety);

    // 2. sexual_content (sur une autre cible)
    $post2 = Post::factory()->create(['user_id' => $this->targetUser->id]);
    $res2 = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $post2->id,
        'reason' => 'sexual_content',
        'details' => 'Nudité explicite non autorisée.',
    ])->assertStatus(201);

    expect($res2->json('data.reason.value'))->toBe('sexual_content');
});

test('POST /api/reports normalizes legacy minor and sexual reasons to child_safety and sexual_content', function () {
    Passport::actingAs($this->reporter);

    $post1 = Post::factory()->create(['user_id' => $this->targetUser->id]);
    $res1 = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $post1->id,
        'reason' => 'minor',
    ])->assertStatus(201);

    expect($res1->json('data.reason.value'))->toBe('child_safety');

    $post2 = Post::factory()->create(['user_id' => $this->targetUser->id]);
    $res2 = $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $post2->id,
        'reason' => 'sexual',
    ])->assertStatus(201);

    expect($res2->json('data.reason.value'))->toBe('sexual_content');
});

test('child_safety report immediately alerts authorized moderation operators', function () {
    Notification::fake();

    Passport::actingAs($this->reporter);

    $this->postJson('/api/reports', [
        'target_type' => 'post',
        'target_id' => (string) $this->post->id,
        'reason' => 'child_safety',
        'details' => 'Alerte urgente sur protection des mineurs.',
    ])->assertStatus(201);

    Notification::assertSentTo([$this->adminUser, $this->modUser], ChildSafetyAlertNotification::class);
});

test('child_safety reports are always placed at the very top of the moderation queue', function () {
    // Création d'anciens rapports normaux
    $olderSpamReport = Report::create([
        'reporter_id' => $this->reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => (string) $this->post->id,
        'reason' => ReportReason::Spam,
        'status' => 'pending',
        'priority' => 'normal',
        'created_at' => now()->subHours(5),
    ]);

    $newerHateReport = Report::create([
        'reporter_id' => $this->reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => (string) $this->post->id,
        'reason' => ReportReason::HateSpeech,
        'status' => 'pending',
        'priority' => 'normal',
        'created_at' => now()->subMinutes(10),
    ]);

    // Rapport child_safety créé entre les deux
    $childSafetyReport = Report::create([
        'reporter_id' => $this->reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => (string) $this->post->id,
        'reason' => ReportReason::ChildSafety,
        'status' => 'pending',
        'created_at' => now()->subHours(2),
    ]);

    /** @var ModerationQueueService $queueService */
    $queueService = app(ModerationQueueService::class);
    $result = $queueService->getQueue([], 20);

    $items = $result['reports']->items();

    // Le premier élément doit IMPÉRATIVEMENT être le dossier child_safety
    expect($items[0]->id)->toBe($childSafetyReport->id);
    expect($items[0]->reason)->toBe(ReportReason::ChildSafety);
});

test('Story report normalizes minor and sexual to child_safety and sexual_content', function () {
    Passport::actingAs($this->reporter);

    $story = Story::factory()->create(['user_id' => $this->targetUser->id]);

    $res = $this->postJson("/api/stories/{$story->id}/report", [
        'reason' => 'minor',
        'details' => 'Contenu inapproprié avec mineur',
    ])->assertStatus(201);

    $this->assertDatabaseHas('story_reports', [
        'story_id' => $story->id,
        'reason' => 'child_safety',
    ]);
});
