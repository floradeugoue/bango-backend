<?php

use App\Enums\Listing\ListingStatus;
use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageSector;
use App\Enums\Reel\ReelStatus;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Storage::fake('public');
    Storage::fake('local');

    $this->user = User::factory()->create(['name' => 'Alice Propriétaire']);
    $this->otherUser = User::factory()->create(['name' => 'Bob Extérieur']);
});

test('POST /api/pages crée une Page Professionnelle avec création automatique du rôle Owner', function () {
    Passport::actingAs($this->user);

    $logo = UploadedFile::fake()->image('logo.png', 200, 200);
    $cover = UploadedFile::fake()->image('cover.jpg', 800, 300);

    $response = $this->postJson('/api/pages', [
        'name' => 'Cabinet Notarial & Conseils',
        'handle' => 'cabinet-notarial',
        'description' => 'Étude notariale et conseils juridiques.',
        'sector' => PageSector::Services->value,
        'city' => 'Douala',
        'neighbourhood' => 'Bonanjo',
        'email' => 'contact@notariat.cm',
        'phone' => '+237699112233',
        'logo' => $logo,
        'cover' => $cover,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Cabinet Notarial & Conseils')
        ->assertJsonPath('data.handle', 'cabinet-notarial')
        ->assertJsonPath('data.sector', PageSector::Services->value)
        ->assertJsonPath('data.city', 'Douala')
        ->assertJsonPath('data.is_owner', true)
        ->assertJsonPath('data.current_user_role', PageRole::Owner->value);

    $this->assertDatabaseHas('pages', [
        'name' => 'Cabinet Notarial & Conseils',
        'handle' => 'cabinet-notarial',
        'owner_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('page_members', [
        'user_id' => $this->user->id,
        'role' => PageRole::Owner->value,
        'status' => 'accepted',
    ]);
});

test('GET /api/pages/{identifier} permet la consultation publique par ID ou par Handle', function () {
    $page = Page::factory()->create([
        'owner_id' => $this->user->id,
        'name' => 'Menuiserie Moderne',
        'handle' => 'menuiserie-moderne',
        'sector' => PageSector::Craftsman,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    // 1. Consultation par ID
    $responseById = $this->getJson("/api/pages/{$page->id}");
    $responseById->assertOk()
        ->assertJsonPath('data.id', $page->id)
        ->assertJsonPath('data.name', 'Menuiserie Moderne')
        ->assertJsonPath('data.is_certified', true)
        ->assertJsonPath('data.badge', 'page_certified');

    // 2. Consultation par Handle
    $responseByHandle = $this->getJson('/api/pages/menuiserie-moderne');
    $responseByHandle->assertOk()
        ->assertJsonPath('data.id', $page->id)
        ->assertJsonPath('data.handle', 'menuiserie-moderne');
});

test('GET /api/me/pages et /api/pages/my-pages retournent les pages de l\'utilisateur', function () {
    Passport::actingAs($this->user);

    $page1 = Page::factory()->create(['owner_id' => $this->user->id, 'name' => 'Page 1']);
    $page1->members()->create([
        'user_id' => $this->user->id,
        'role' => PageRole::Owner,
        'status' => 'accepted',
    ]);

    $page2 = Page::factory()->create(['name' => 'Page 2']);
    $page2->members()->create([
        'user_id' => $this->user->id,
        'role' => PageRole::Editor,
        'status' => 'accepted',
    ]);

    // Route /api/me/pages
    $responseMe = $this->getJson('/api/me/pages');
    $responseMe->assertOk()
        ->assertJsonCount(2, 'data');

    // Alias /api/pages/my-pages
    $responseMyPages = $this->getJson('/api/pages/my-pages');
    $responseMyPages->assertOk()
        ->assertJsonCount(2, 'data');
});

test('PUT /api/pages/{page} permet au propriétaire de modifier les informations de la page', function () {
    Passport::actingAs($this->user);

    $page = Page::factory()->create(['owner_id' => $this->user->id, 'name' => 'Ancien Nom']);
    $page->members()->create([
        'user_id' => $this->user->id,
        'role' => PageRole::Owner,
        'status' => 'accepted',
    ]);

    $response = $this->putJson("/api/pages/{$page->id}", [
        'name' => 'Nouveau Nom Officiel',
        'description' => 'Nouvelle description mise à jour.',
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Nouveau Nom Officiel')
        ->assertJsonPath('data.city', 'Yaoundé')
        ->assertJsonPath('data.neighbourhood', 'Bastos');

    $this->assertDatabaseHas('pages', [
        'id' => $page->id,
        'name' => 'Nouveau Nom Officiel',
        'city' => 'Yaoundé',
    ]);
});

test('DELETE /api/pages/{page} supprime logiquement la page', function () {
    Passport::actingAs($this->user);

    $page = Page::factory()->create(['owner_id' => $this->user->id]);
    $page->members()->create([
        'user_id' => $this->user->id,
        'role' => PageRole::Owner,
        'status' => 'accepted',
    ]);

    $response = $this->deleteJson("/api/pages/{$page->id}");
    $response->assertOk();

    $this->assertSoftDeleted('pages', [
        'id' => $page->id,
    ]);
});

test('POST /api/pages/{page}/switch-context bascule le contexte applicatif de l\'utilisateur (PG-04)', function () {
    Passport::actingAs($this->user);

    $page = Page::factory()->create(['owner_id' => $this->user->id, 'name' => 'Agence Immobilière Horizon']);
    $page->members()->create([
        'user_id' => $this->user->id,
        'role' => PageRole::Owner,
        'status' => 'accepted',
    ]);

    $response = $this->postJson("/api/pages/{$page->id}/switch-context");

    $response->assertOk()
        ->assertJsonPath('data.context_type', 'page')
        ->assertJsonPath('data.page.name', 'Agence Immobilière Horizon')
        ->assertJsonPath('data.member_role', 'owner')
        ->assertJsonPath('data.permissions.can_edit', true)
        ->assertJsonPath('data.permissions.can_publish', true);
});

test('GET /api/search/pages filtre les pages par secteur, ville et certification (RC)', function () {
    Page::factory()->create([
        'name' => 'Immobilier Douala Centre',
        'sector' => PageSector::RealEstateAgency,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    Page::factory()->create([
        'name' => 'Plomberie Yaoundé Express',
        'sector' => PageSector::Craftsman,
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
        'certification_status' => PageCertificationStatus::None,
    ]);

    // 1. Recherche par terme "Immobilier"
    $resSearch = $this->getJson('/api/search/pages?q=Immobilier');
    $resSearch->assertOk()->assertJsonCount(1, 'data');

    // 2. Recherche par secteur
    $resSector = $this->getJson('/api/search/pages?sector=craftsman');
    $resSector->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Plomberie Yaoundé Express');

    // 3. Recherche avec filtre certified=1
    $resCertified = $this->getJson('/api/search/pages?certified=1');
    $resCertified->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Immobilier Douala Centre');
});

test('GET /api/pages/{page}/listings expose la vitrine immobilière de l\'agence (IM)', function () {
    $page = Page::factory()->create([
        'owner_id' => $this->user->id,
        'sector' => PageSector::RealEstateAgency,
    ]);

    $listing1 = Listing::factory()->create([
        'owner_id' => $this->user->id,
        'page_id' => $page->id,
        'title' => 'Villa avec piscine à Bonapriso',
        'status' => ListingStatus::Published,
        'created_at' => now()->subMinute(),
    ]);

    $listing2 = Listing::factory()->create([
        'owner_id' => $this->user->id,
        'page_id' => $page->id,
        'title' => 'Appartement 3 pièces Akwa',
        'status' => ListingStatus::Published,
    ]);

    $response = $this->getJson("/api/pages/{$page->id}/listings");
    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', $listing2->title);
});

test('GET /pages/{page}/posts liste les publications Square de la page', function () {
    $page = Page::factory()->create([
        'owner_id' => $this->user->id,
    ]);

    Post::factory()->create([
        'user_id' => $this->user->id,
        'page_id' => $page->id,
        'body' => 'Première publication de la page',
        'is_archived' => false,
        'created_at' => now()->subMinute(),
    ]);

    Post::factory()->create([
        'user_id' => $this->user->id,
        'page_id' => $page->id,
        'body' => 'Deuxième publication de la page',
        'is_archived' => false,
        'created_at' => now(),
    ]);

    // Post archivé (ne doit pas apparaître)
    Post::factory()->create([
        'user_id' => $this->user->id,
        'page_id' => $page->id,
        'body' => 'Publication archivée',
        'is_archived' => true,
    ]);

    $response = $this->getJson("/api/pages/{$page->id}/posts");
    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.body', 'Deuxième publication de la page')
        ->assertJsonPath('data.0.page_id', $page->id)
        ->assertJsonPath('data.0.is_page_post', true);
});

test('GET /pages/{page}/reels liste les Reels publiés de la page', function () {
    $page = Page::factory()->create([
        'owner_id' => $this->user->id,
    ]);

    Reel::factory()->create([
        'user_id' => $this->user->id,
        'page_id' => $page->id,
        'caption' => 'Reel officiel de l agence',
        'status' => ReelStatus::Ready,
        'published_at' => now(),
    ]);

    Reel::factory()->create([
        'user_id' => $this->user->id,
        'page_id' => $page->id,
        'caption' => 'Reel en cours de traitement',
        'status' => ReelStatus::Processing,
    ]);

    $response = $this->getJson("/api/pages/{$page->id}/reels");
    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.caption', 'Reel officiel de l agence')
        ->assertJsonPath('data.0.page_id', $page->id)
        ->assertJsonPath('data.0.is_page_reel', true);
});

test('PageResource expose posts_count, listings_count et agents_count pour real_estate_agency', function () {
    $agency = Page::factory()->create([
        'owner_id' => $this->user->id,
        'sector' => PageSector::RealEstateAgency,
    ]);

    // 2 posts
    Post::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'page_id' => $agency->id,
    ]);

    // 3 listings (2 published, 1 draft)
    Listing::factory()->count(2)->create([
        'owner_id' => $this->user->id,
        'page_id' => $agency->id,
        'status' => ListingStatus::Published,
    ]);
    Listing::factory()->create([
        'owner_id' => $this->user->id,
        'page_id' => $agency->id,
        'status' => ListingStatus::Draft,
    ]);

    // 2 membres agents avec badge agent + 1 membre simple
    $agentUser1 = User::factory()->create(['badge' => 'agent']);
    $agentUser2 = User::factory()->create(['badge' => 'agent']);
    $normalUser = User::factory()->create(['badge' => 'none']);

    PageMember::create([
        'page_id' => $agency->id,
        'user_id' => $agentUser1->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
    ]);
    PageMember::create([
        'page_id' => $agency->id,
        'user_id' => $agentUser2->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
    ]);
    PageMember::create([
        'page_id' => $agency->id,
        'user_id' => $normalUser->id,
        'role' => PageRole::Moderator,
        'status' => PageMemberStatus::Accepted,
    ]);

    $response = $this->getJson("/api/pages/{$agency->id}");
    $response->assertOk()
        ->assertJsonPath('data.posts_count', 2)
        ->assertJsonPath('data.listings_count', 2)
        ->assertJsonPath('data.agents_count', 2);

    // Pour un autre secteur (ex: Commerce), agents_count doit être null
    $commerce = Page::factory()->create([
        'owner_id' => $this->user->id,
        'sector' => PageSector::Commerce,
    ]);

    $responseCommerce = $this->getJson("/api/pages/{$commerce->id}");
    $responseCommerce->assertOk()
        ->assertJsonPath('data.posts_count', 0)
        ->assertJsonPath('data.listings_count', 0)
        ->assertJsonPath('data.agents_count', null);
});
