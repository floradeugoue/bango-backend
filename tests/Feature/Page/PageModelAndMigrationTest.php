<?php

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Models\Page\PageMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('une Page Professionnelle peut être créée avec succès avec ses attributs métier', function () {
    $owner = User::factory()->create();

    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Boutique Élégance',
        'handle' => 'boutique-elegance',
        'description' => 'Boutique de prêt-à-porter de luxe.',
        'sector' => PageSector::Commerce,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'certification_status' => PageCertificationStatus::None,
        'status' => PageStatus::Active,
    ]);

    expect($page->exists)->toBeTrue()
        ->and($page->id)->toBeGreaterThan(0)
        ->and($page->name)->toBe('Boutique Élégance')
        ->and($page->handle)->toBe('boutique-elegance')
        ->and($page->sector)->toBe(PageSector::Commerce)
        ->and($page->isCertified())->toBeFalse()
        ->and($page->isActive())->toBeTrue()
        ->and($page->isOwner($owner))->toBeTrue();
});

test('le handle d\'une Page Professionnelle est strictement unique', function () {
    $owner1 = User::factory()->create();
    $owner2 = User::factory()->create();

    Page::create([
        'owner_id' => $owner1->id,
        'name' => 'Agence Prestige',
        'handle' => 'prestige-immo',
        'sector' => PageSector::RealEstateAgency,
    ]);

    // Tentative de créer une seconde page avec le même handle
    Page::create([
        'owner_id' => $owner2->id,
        'name' => 'Prestige Immobilier',
        'handle' => 'prestige-immo',
        'sector' => PageSector::RealEstateAgency,
    ]);
})->throws(QueryException::class);

test('l\'association entre un utilisateur et une page est unique au niveau pivot', function () {
    $page = Page::factory()->create();
    $user = User::factory()->create();

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $user->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
    ]);

    // Double association directe en base
    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $user->id,
        'role' => PageRole::Moderator,
        'status' => PageMemberStatus::Pending,
    ]);
})->throws(QueryException::class);

test('les relations Eloquent de Page, PageMember et PageCertification fonctionnent correctement', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create();

    $page = Page::factory()->create(['owner_id' => $owner->id]);

    $ownerMember = PageMember::factory()->create([
        'page_id' => $page->id,
        'user_id' => $owner->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
    ]);

    $editorMember = PageMember::factory()->create([
        'page_id' => $page->id,
        'user_id' => $editor->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
        'invited_by_id' => $owner->id,
    ]);

    $certification = PageCertification::factory()->create([
        'page_id' => $page->id,
        'submitted_by_id' => $owner->id,
    ]);

    expect($page->owner->id)->toBe($owner->id)
        ->and($page->members)->toHaveCount(2)
        ->and($page->activeMembers)->toHaveCount(2)
        ->and($page->certifications)->toHaveCount(1)
        ->and($page->latestCertification->id)->toBe($certification->id)
        ->and($editorMember->inviter->id)->toBe($owner->id)
        ->and($owner->ownedPages)->toHaveCount(1)
        ->and($editor->pages)->toHaveCount(1);
});
