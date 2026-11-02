<?php

use App\Enums\Message\ConversationMemberRole;
use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageStatus;
use App\Enums\Support\TicketCategory;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingLike;
use App\Models\Listing\ListingSave;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Profile\Follow;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Square\PostLike;
use App\Models\Story\Story;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

test('X-Bango-Page middleware authorizes page owner or active accepted member and denies outsiders', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();

    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Boulangerie Bango',
        'handle' => 'boulangerie_bango',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);

    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $member->id,
        'role' => PageRole::Editor,
        'status' => 'accepted',
        'accepted_at' => now(),
    ]);

    // Outsider receives 403 when trying to act as the page
    Passport::actingAs($outsider);
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/notifications')
        ->assertStatus(403);

    // Owner succeeds
    Passport::actingAs($owner);
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/notifications')
        ->assertStatus(200);

    // Member succeeds
    Passport::actingAs($member);
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/notifications')
        ->assertStatus(200);
});

test('1. Stories created with X-Bango-Page are saved with page_id and rendered with page author', function () {
    $owner = User::factory()->create();
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Bango Tech',
        'handle' => 'bango_tech',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
    ]);

    $file = UploadedFile::fake()->image('story.jpg');

    Passport::actingAs($owner);
    $response = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson('/api/stories', [
            'media' => $file,
            'type' => 'image',
        ]);

    $response->assertStatus(201);
    $storyId = $response->json('data.id');

    $story = Story::findOrFail($storyId);
    expect((int) $story->page_id)->toBe((int) $page->id);

    // Verify rendered author is the Page
    $author = $response->json('data.author');
    expect($author['name'])->toBe('Bango Tech');
    expect($author['handle'])->toBe('bango_tech');
    expect($author['is_certified'])->toBeTrue();
});

test('2. Likes and comments are saved with page_id and rendered with page author', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Restaurant Saveur',
        'handle' => 'resto_saveur',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    $post = Post::create([
        'user_id' => $otherUser->id,
        'body' => 'Hello world from user!',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    Passport::actingAs($owner);

    // Like with X-Bango-Page
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson("/api/square-cr1/posts/{$post->id}/like")
        ->assertStatus(200)
        ->assertJson(['liked' => true]);

    $like = PostLike::where('post_id', $post->id)->first();
    expect((int) $like->page_id)->toBe((int) $page->id);

    // Liked posts list with X-Bango-Page includes this post
    $likedPostsRes = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/square-cr1/posts/liked')
        ->assertStatus(200);
    expect(collect($likedPostsRes->json('data'))->pluck('id')->all())->toContain((string) $post->id);

    // Comment with X-Bango-Page
    $commentResponse = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson("/api/square-cr1/posts/{$post->id}/comments", [
            'body' => 'Super publication !',
        ])
        ->assertStatus(201);

    $commentId = $commentResponse->json('data.id');
    $comment = PostComment::findOrFail($commentId);
    expect((int) $comment->page_id)->toBe((int) $page->id);

    // Verify comment author identity renders the Page
    $author = $commentResponse->json('data.author');
    expect($author['name'])->toBe('Restaurant Saveur');
    expect($author['handle'])->toBe('resto_saveur');
    expect($author['is_certified'])->toBeTrue();
});

test('3. Follow and unfollow are performed in the name of the page', function () {
    $owner = User::factory()->create();
    $targetUser = User::factory()->create();
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Agence Immo',
        'handle' => 'agence_immo',
        'status' => PageStatus::Active,
    ]);

    Passport::actingAs($owner);

    // Follow target as page
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson("/api/profile/{$targetUser->id}/follow")
        ->assertStatus(200)
        ->assertJson(['status' => 'followed']);

    $follow = Follow::where('follower_page_id', $page->id)
        ->where('following_id', $targetUser->id)
        ->first();
    expect($follow)->not->toBeNull();

    // Check following list as page
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/profile/following')
        ->assertStatus(200)
        ->assertJsonFragment(['id' => $targetUser->id, 'isFollowing' => true]);

    // Unfollow as page
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->deleteJson("/api/profile/{$targetUser->id}/follow")
        ->assertStatus(200)
        ->assertJson(['status' => 'unfollowed']);

    expect(Follow::where('follower_page_id', $page->id)->count())->toBe(0);
});

test('4. Messages sent with X-Bango-Page have page_id and render sender as the page', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Support Boutique',
        'handle' => 'support_boutique',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    $conversation = Conversation::create([
        'type' => 'direct',
        'created_by_id' => $owner->id,
        'page_id' => $page->id,
    ]);
    $conversation->members()->create(['user_id' => $owner->id, 'role' => ConversationMemberRole::Admin]);
    $conversation->members()->create(['user_id' => $otherUser->id, 'role' => ConversationMemberRole::Member]);

    Passport::actingAs($owner);

    // Send message as page
    $msgResponse = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson("/api/messages/threads/{$conversation->id}/messages", [
            'body' => 'Bonjour, comment pouvons-nous vous aider ?',
        ])
        ->assertStatus(201);

    $msgId = $msgResponse->json('data.id');
    $message = Message::findOrFail($msgId);
    expect((int) $message->page_id)->toBe((int) $page->id);

    // Verify sender displayed as the page
    $sender = $msgResponse->json('data.sender');
    expect($sender['name'])->toBe('Support Boutique');
    expect($sender['is_certified'])->toBeTrue();

    // Check threads list for page
    $threadsResponse = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/messages/threads')
        ->assertStatus(200);

    expect(collect($threadsResponse->json('data'))->pluck('id')->all())->toContain($conversation->id);
});

test('5. Notifications are scoped to the page when X-Bango-Page is sent', function () {
    $owner = User::factory()->create();
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Page Notifs',
        'handle' => 'page_notifs',
        'status' => PageStatus::Active,
    ]);

    // Create user notification (without page_id)
    $owner->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Social\SocialNotification',
        'data' => ['message' => 'Notif perso', 'page_id' => null],
    ]);

    // Create page notification (with page_id)
    $owner->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\Notifications\Social\SocialNotification',
        'data' => ['message' => 'Notif page', 'page_id' => $page->id],
    ]);

    Passport::actingAs($owner);

    // Without header -> only user notification, unread_count is 1, unread_by_identity contains account and pages
    $resUser = $this->getJson('/api/notifications')
        ->assertStatus(200);
    $dataUser = $resUser->json('data');
    expect(collect($dataUser)->pluck('data.message')->all())->toContain('Notif perso');
    expect(collect($dataUser)->pluck('data.message')->all())->not->toContain('Notif page');
    expect($resUser->json('unread_count'))->toBe(1);
    expect($resUser->json('unread_by_identity'))->toBe([
        'account' => 1,
        'pages' => [
            (string) $page->id => 1,
        ],
    ]);

    // With header -> only page notification, unread_count is 1 (for page)
    $resPage = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/notifications')
        ->assertStatus(200);
    $dataPage = $resPage->json('data');
    expect(collect($dataPage)->pluck('data.message')->all())->toContain('Notif page');
    expect(collect($dataPage)->pluck('data.message')->all())->not->toContain('Notif perso');
    expect($resPage->json('unread_count'))->toBe(1);
    expect($resPage->json('unread_by_identity'))->toBe([
        'account' => 1,
        'pages' => [
            (string) $page->id => 1,
        ],
    ]);
});

test('6. Saved and liked reels are scoped to the page when X-Bango-Page is sent', function () {
    $owner = User::factory()->create();
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Page Reels',
        'handle' => 'page_reels',
        'status' => PageStatus::Active,
    ]);

    $reel = Reel::factory()->create();

    Passport::actingAs($owner);

    // Toggle save as page
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson("/api/reels/{$reel->id}/save")
        ->assertStatus(200)
        ->assertJson(['data' => ['saved' => true]]);

    // List saved reels as page
    $resSaved = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/reels/user/saved')
        ->assertStatus(200);
    expect(collect($resSaved->json('data'))->pluck('id')->all())->toContain($reel->id);

    // List saved reels as personal user -> should be empty
    $this->flushHeaders();
    $resUserSaved = $this->getJson('/api/reels/user/saved')
        ->assertStatus(200);
    expect(collect($resUserSaved->json('data'))->pluck('id')->all())->not->toContain($reel->id);
});

test('7. Feed neighbourhood uses page neighbourhood when X-Bango-Page is sent', function () {
    $owner = User::factory()->create([
        'city' => 'Douala',
        'neighbourhood' => 'Bonanjo',
    ]);

    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Page Quartier',
        'handle' => 'page_quartier',
        'status' => PageStatus::Active,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);

    // Create post in user neighbourhood (Bonanjo)
    $bonanjoAuthor = User::factory()->create(['city' => 'Douala', 'neighbourhood' => 'Bonanjo']);
    $bonanjoPost = Post::create([
        'user_id' => $bonanjoAuthor->id,
        'body' => 'Post in Bonanjo',
        'place_city' => 'Douala',
        'place_neighbourhood' => 'Bonanjo',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    // Create post in page neighbourhood (Akwa)
    $akwaAuthor = User::factory()->create(['city' => 'Douala', 'neighbourhood' => 'Akwa']);
    $akwaPost = Post::create([
        'user_id' => $akwaAuthor->id,
        'body' => 'Post in Akwa',
        'place_city' => 'Douala',
        'place_neighbourhood' => 'Akwa',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    Passport::actingAs($owner);

    // Feed without X-Bango-Page -> uses user neighbourhood (Bonanjo)
    $resUserFeed = $this->getJson('/api/square-cr1/feed?tab=neighbourhood')
        ->assertStatus(200);
    $userPostIds = collect($resUserFeed->json('data'))->pluck('id')->all();
    expect($userPostIds)->toContain((string) $bonanjoPost->id);
    expect($userPostIds)->not->toContain((string) $akwaPost->id);

    // Feed with X-Bango-Page -> uses page neighbourhood (Akwa)
    $resPageFeed = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/square-cr1/feed?tab=neighbourhood')
        ->assertStatus(200);
    $pagePostIds = collect($resPageFeed->json('data'))->pluck('id')->all();
    expect($pagePostIds)->toContain((string) $akwaPost->id);
    expect($pagePostIds)->not->toContain((string) $bonanjoPost->id);
});

test('8. Support tickets created with X-Bango-Page save page_id and show page as author', function () {
    $owner = User::factory()->create();
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Bango Services Pro',
        'handle' => 'bango_services_pro',
        'status' => PageStatus::Active,
        'logo_url' => 'https://example.com/logo.png',
    ]);

    Passport::actingAs($owner);

    $res = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson('/api/support/tickets', [
            'subject' => 'Problème de facturation pro',
            'category' => TicketCategory::ProPages->value,
            'message' => 'Nous avons une question sur notre abonnement.',
        ])
        ->assertStatus(201);

    expect($res->json('data.page_id'))->toBe((int) $page->id);
    expect($res->json('data.author.name'))->toBe('Bango Services Pro');
    expect($res->json('data.author.handle'))->toBe('bango_services_pro');
    expect($res->json('data.author.logo_url'))->toBe('https://example.com/logo.png');

    // List mine with X-Bango-Page -> returns the page ticket
    $resList = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/support/tickets/mine')
        ->assertStatus(200);
    expect(collect($resList->json('data'))->pluck('id')->all())->toContain($res->json('data.id'));

    // List mine without X-Bango-Page -> returns personal tickets only (empty)
    $this->flushHeaders();
    $resListPersonal = $this->getJson('/api/support/tickets/mine')
        ->assertStatus(200);
    expect(collect($resListPersonal->json('data'))->pluck('id')->all())->not->toContain($res->json('data.id'));
});

test('9. Listing likes, saves, and comments with X-Bango-Page record page_id', function () {
    $owner = User::factory()->create();
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Agence Immobilière Bango',
        'handle' => 'immo_bango',
        'status' => PageStatus::Active,
        'logo_url' => 'https://example.com/immo_logo.png',
    ]);

    $seller = User::factory()->create();
    $listing = Listing::create([
        'owner_id' => $seller->id,
        'created_by_id' => $seller->id,
        'title' => 'Superbe Villa Bastos',
        'reference' => 'LST-TEST-001',
        'kind' => 'villa',
        'mode' => 'sale',
        'price' => 150000000,
        'currency' => 'XAF',
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
        'status' => 'published',
    ]);

    Passport::actingAs($owner);

    // 1. Like listing as page
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson("/api/listings/{$listing->id}/like")
        ->assertStatus(200)
        ->assertJson(['liked' => true]);

    $like = ListingLike::where('listing_id', $listing->id)
        ->where('page_id', $page->id)
        ->first();
    expect($like)->not->toBeNull();

    // 2. Save listing as page
    $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson("/api/listings/{$listing->id}/save")
        ->assertStatus(200)
        ->assertJson(['saved' => true]);

    $save = ListingSave::where('listing_id', $listing->id)
        ->where('page_id', $page->id)
        ->first();
    expect($save)->not->toBeNull();

    // 3. Comment listing as page
    $commentRes = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->postJson("/api/listings/{$listing->id}/comments", [
            'content' => 'Cette villa est magnifique, pouvons-nous visiter ?',
        ])
        ->assertStatus(201);

    expect($commentRes->json('data.page_id'))->toBe((int) $page->id);
    expect($commentRes->json('data.author.name'))->toBe('Agence Immobilière Bango');
    expect($commentRes->json('data.author.handle'))->toBe('immo_bango');
    expect($commentRes->json('data.author.logo_url'))->toBe('https://example.com/immo_logo.png');
});

test('10. Notifications isolation and unread_by_identity counts', function () {
    $user = User::factory()->create();
    $page = Page::create([
        'owner_id' => $user->id,
        'name' => 'Bango Tech Shop',
        'handle' => 'bango_tech_shop',
        'status' => PageStatus::Active,
    ]);

    $actor = User::factory()->create();

    // Notification personnelle
    $user->notify(new SocialNotification('like', $actor, [
        'post_id' => 101,
        'text' => 'A aimé votre publication personnelle',
    ]));

    // Notification liée à la page
    $user->notify(new SocialNotification('like', $actor, [
        'post_id' => 202,
        'page_id' => $page->id,
        'text' => 'A aimé la publication de votre page',
    ]));

    Passport::actingAs($user);

    // Requête sans en-tête -> notifications personnelles uniquement
    $resPersonal = $this->getJson('/api/notifications')
        ->assertStatus(200);

    expect($resPersonal->json('unread_count'))->toBe(1);
    expect($resPersonal->json('unread_by_identity.account'))->toBe(1);
    expect($resPersonal->json('unread_by_identity.pages.'.(string) $page->id))->toBe(1);
    expect($resPersonal->json('data.0.data.metadata.post_id'))->toBe(101);

    // Requête avec X-Bango-Page -> notifications de la page uniquement
    $resPage = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/notifications')
        ->assertStatus(200);

    expect($resPage->json('unread_count'))->toBe(1);
    expect($resPage->json('unread_by_identity.account'))->toBe(1);
    expect($resPage->json('unread_by_identity.pages.'.(string) $page->id))->toBe(1);
    expect($resPage->json('data.0.data.metadata.post_id'))->toBe(202);
});
