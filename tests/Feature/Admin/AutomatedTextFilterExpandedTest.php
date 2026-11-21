<?php

namespace Tests\Feature\Admin;

use App\Enums\Listing\ListingStatus;
use App\Enums\Page\PageStatus;
use App\Models\Page\Page;
use App\Models\Story\Story;
use App\Models\Support\ModerationFilterLog;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use App\Notifications\Support\ModerationDecisionNotification;
use App\Services\Admin\AutomatedTextFilterService;
use App\Services\Listing\ListingService;
use App\Services\Page\PageService;
use App\Services\Story\StoryService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    Notification::fake();
    Storage::fake('public');
});

it('detects discrimination patterns and enforces 1.0 sanction scale', function () {
    $service = app(AutomatedTextFilterService::class);
    $user = User::factory()->create();

    // 1re infraction (discrimation ethnique/tribale) : step 1 = warned
    $res1 = $service->scanText('Appartement disponible mais pas de bamiléké merci', 'listing', 999, $user);

    expect($res1)->not->toBeNull()
        ->and($res1['flagged'])->toBeTrue()
        ->and($res1['category'])->toBe('discrimination')
        ->and($res1['step'])->toBe(1)
        ->and($res1['action_taken'])->toBe('warned');

    Notification::assertSentTo($user, ModerationDecisionNotification::class, function ($n) {
        return $n->action === 'warn_author';
    });

    // 2e infraction (discrimination religieuse) : step 2 = removed
    $res2 = $service->scanText('Réservé aux chrétiens uniquement', 'listing', 999, $user);

    expect($res2['flagged'])->toBeTrue()
        ->and($res2['category'])->toBe('discrimination')
        ->and($res2['step'])->toBe(2)
        ->and($res2['action_taken'])->toBe('removed');

    expect(UserAccountSanction::where('user_id', $user->id)->where('type', 'content_removal')->exists())->toBeTrue();

    // 3e infraction (discrimination handicap/santé) : step 3 = restricted 7j
    $res3 = $service->scanText('Interdit aux handicapés et pas de sida', 'listing', 999, $user);

    expect($res3['flagged'])->toBeTrue()
        ->and($res3['category'])->toBe('discrimination')
        ->and($res3['step'])->toBe(3)
        ->and($res3['action_taken'])->toBe('restricted');

    $user->refresh();
    expect($user->restricted_until)->not->toBeNull()
        ->and($user->restricted_until->isFuture())->toBeTrue();
});

it('scans listing title and description upon creation and update', function () {
    $landlord = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);
    Passport::actingAs($landlord);

    // Création d'une annonce contenant une clause discriminatoire
    $listing = app(ListingService::class)->createOrPublishListing($landlord, [
        'title' => 'Bel appartement à Akwa',
        'description' => 'Quartier calme, mais pas de bamiléké dans l\'immeuble.',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 150000,
        'city' => 'Douala',
    ], submitToReview: false);

    // 1re infraction : avertissement automatique
    $log = ModerationFilterLog::where('user_id', $landlord->id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->category)->toBe('discrimination')
        ->and($log->content_type)->toBe('listing')
        ->and($log->action_taken)->toBe('warned');

    // Modification avec une 2e clause discriminatoire : déclenche le retrait automatique
    $updated = app(ListingService::class)->updateListingDetails($listing, $landlord, [
        'description' => 'Mise à jour : réservé aux bétis exclusivement.',
    ]);

    $log2 = ModerationFilterLog::where('user_id', $landlord->id)->latest('id')->first();
    expect($log2)->not->toBeNull()
        ->and($log2->step)->toBe(2)
        ->and($log2->action_taken)->toBe('removed');

    $listing->refresh();
    expect($listing->status)->toBe(ListingStatus::Draft)
        ->and($listing->withdrawn_by_bango)->toBeTrue();
});

it('scans profile bio and display name upon update', function () {
    $user = User::factory()->create([
        'display_name' => 'Jean Dupont',
        'bio' => 'Bienvenue sur mon profil.',
    ]);
    Passport::actingAs($user);

    // Mise à jour de la bio avec propos discriminatoires
    $response = $this->putJson('/api/profile/bio', [
        'bio' => 'Vraie communauté ici, interdit aux étrangers et pas d\'étrangers.',
    ]);

    $response->assertStatus(200);

    $log = ModerationFilterLog::where('user_id', $user->id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->category)->toBe('discrimination')
        ->and($log->content_type)->toBe('user')
        ->and($log->action_taken)->toBe('warned');

    // 2e mise à jour : step 2 = reset bio
    $response2 = $this->putJson('/api/profile/bio', [
        'bio' => 'Deuxième avertissement : sale béti dehors.',
    ]);

    $response2->assertStatus(200);

    $user->refresh();
    expect($user->bio)->toBeNull(); // réinitialisé par le retrait automatique
});

it('scans page creation and update and suspends page on step 2', function () {
    $owner = User::factory()->create();
    Passport::actingAs($owner);

    // 1. Création d'une page avec description discriminatoire -> Avertissement (step 1)
    $page = app(PageService::class)->create($owner, [
        'name' => 'Club Exclusif',
        'description' => 'Cercle privé, réservé aux ressortissants locaux et pas de bamiléké.',
        'handle' => 'club-exclusif',
    ]);

    $log = ModerationFilterLog::where('user_id', $owner->id)->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->category)->toBe('discrimination')
        ->and($log->content_type)->toBe('page')
        ->and($log->step)->toBe(1);

    // 2. Modification de la page avec nouvelle infraction -> Retrait / Suspension (step 2)
    $updated = app(PageService::class)->update($page, [
        'description' => 'Mise à jour : outrage aux tribus et haine tribale.',
    ]);

    $page->refresh();
    expect($page->status)->toBe(PageStatus::Suspended);
});

it('scans story creation and removes story on step 2', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $file = UploadedFile::fake()->image('story.jpg', 600, 600);

    // 1re Story avec texte discriminatoire -> step 1
    $story1 = app(StoryService::class)->createStory($user, [
        'caption' => 'Ma première story : pas de chrétiens ici.',
    ], ['file' => $file]);

    $log1 = ModerationFilterLog::where('user_id', $user->id)->latest('id')->first();
    expect($log1)->not->toBeNull()
        ->and($log1->category)->toBe('discrimination')
        ->and($log1->content_type)->toBe('story')
        ->and($log1->step)->toBe(1);

    // 2e Story avec stickers texte discriminatoire -> step 2 (retrait immédiat)
    $file2 = UploadedFile::fake()->image('story2.jpg', 600, 600);
    $story2 = app(StoryService::class)->createStory($user, [
        'caption' => 'Story neutre',
        'stickers' => [
            [
                'type' => 'text',
                'text' => 'Texte incrusté : interdit aux handicapés',
                'x' => 0.5,
                'y' => 0.5,
            ],
        ],
    ], ['file' => $file2]);

    $log2 = ModerationFilterLog::where('user_id', $user->id)->latest('id')->first();
    expect($log2)->not->toBeNull()
        ->and($log2->step)->toBe(2)
        ->and($log2->action_taken)->toBe('removed');

    expect(Story::find($story2->id))->toBeNull(); // supprimé (soft deleted)
});
