<?php

namespace App\Services\Support;

use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\Story\Story;
use App\Models\User;
use App\Models\Visit\VisitSlot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DemoAccountCleanupService
{
    /**
     * Handles des 8 faux comptes de démonstration (RealContentSeeder).
     *
     * @var array<int, string>
     */
    public const DEMO_HANDLES = [
        'samuel_moukoko',
        'suzanne_ngo',
        'horizon_immo',
        'paul_atangana',
        'marcelle_kamga',
        'stephane_deco',
        'carine_mballa',
        'patrick_fopoussi',
    ];

    /**
     * Adresses email associées aux 8 comptes de démonstration.
     *
     * @var array<int, string>
     */
    public const DEMO_EMAILS = [
        'samuel.moukoko@bangoapp.com',
        'suzanne.ngo@bangoapp.com',
        'contact@horizon-immo.cm',
        'paul.atangana@bangoapp.com',
        'marcelle.kamga@bangoapp.com',
        'stephane.deco@bangoapp.com',
        'carine.mballa@bangoapp.com',
        'patrick.fopoussi@bangoapp.com',
    ];

    /**
     * Identifie les comptes de démonstration présents en base de données.
     * Les comptes de recette (recette.prod.*) et les comptes d'administration sont strictement exclus.
     *
     * @return Collection<int, User>
     */
    public function findDemoUsers(): Collection
    {
        return User::query()
            ->where(function ($q) {
                $q->whereIn('handle', self::DEMO_HANDLES)
                    ->orWhereIn('email', self::DEMO_EMAILS)
                    ->orWhere(function ($sub) {
                        $sub->whereIn('id', [7, 8, 9, 10, 11, 12, 13, 14])
                            ->where(function ($nameSub) {
                                $nameSub->where('name', 'like', '%Moukoko%')
                                    ->orWhere('name', 'like', '%Suzanne%')
                                    ->orWhere('name', 'like', '%Horizon%')
                                    ->orWhere('name', 'like', '%Atangana%')
                                    ->orWhere('name', 'like', '%Kamga%')
                                    ->orWhere('name', 'like', '%Nguemo%')
                                    ->orWhere('name', 'like', '%Mballa%')
                                    ->orWhere('name', 'like', '%Fopoussi%');
                            });
                    });
            })
            // Protection absolue : ne jamais toucher aux comptes de recette
            ->where('email', 'not like', 'recette.prod%')
            ->where('handle', 'not like', 'recette.prod%')
            ->where('handle', '!=', 'bango_admin')
            ->get();
    }

    /**
     * Purge définitivement les faux comptes et tous leurs contenus associés.
     *
     * @return array{users_deleted: int, posts_deleted: int, listings_deleted: int, stories_deleted: int, reels_deleted: int, pages_deleted: int}
     */
    public function purge(): array
    {
        $demoUsers = $this->findDemoUsers();
        if ($demoUsers->isEmpty()) {
            return [
                'users_deleted' => 0,
                'posts_deleted' => 0,
                'listings_deleted' => 0,
                'stories_deleted' => 0,
                'reels_deleted' => 0,
                'pages_deleted' => 0,
            ];
        }

        $userIds = $demoUsers->pluck('id')->all();

        return DB::transaction(function () use ($userIds) {
            // 1. Annonces immobilières (Listings)
            $listingIds = Listing::whereIn('owner_id', $userIds)
                ->orWhereIn('created_by_id', $userIds)
                ->pluck('id')
                ->all();

            $listingsCount = count($listingIds);

            if (! empty($listingIds)) {
                if (Schema::hasTable('listing_media')) {
                    ListingMedia::whereIn('listing_id', $listingIds)->delete();
                }
                if (Schema::hasTable('visit_slots')) {
                    VisitSlot::whereIn('listing_id', $listingIds)->orWhereIn('owner_id', $userIds)->delete();
                }
                if (Schema::hasTable('listing_shares')) {
                    DB::table('listing_shares')->whereIn('listing_id', $listingIds)->delete();
                }
                if (Schema::hasTable('listing_saves')) {
                    DB::table('listing_saves')->whereIn('listing_id', $listingIds)->orWhereIn('user_id', $userIds)->delete();
                }
                if (Schema::hasTable('listing_likes')) {
                    DB::table('listing_likes')->whereIn('listing_id', $listingIds)->orWhereIn('user_id', $userIds)->delete();
                }
                if (Schema::hasTable('listing_alerts')) {
                    DB::table('listing_alerts')->whereIn('user_id', $userIds)->delete();
                }
                Listing::whereIn('id', $listingIds)->delete();
            }

            // 2. Le Square (Publications & Médias)
            $postIds = Post::whereIn('user_id', $userIds)->pluck('id')->all();
            $postsCount = count($postIds);

            if (! empty($postIds) || ! empty($userIds)) {
                if (Schema::hasTable('post_media')) {
                    PostMedia::whereIn('post_id', $postIds)->delete();
                }
                if (Schema::hasTable('post_comments')) {
                    DB::table('post_comments')->whereIn('post_id', $postIds)->orWhereIn('user_id', $userIds)->delete();
                }
                if (Schema::hasTable('post_likes')) {
                    DB::table('post_likes')->whereIn('post_id', $postIds)->orWhereIn('user_id', $userIds)->delete();
                }
                if (Schema::hasTable('post_shares')) {
                    DB::table('post_shares')->whereIn('post_id', $postIds)->orWhereIn('user_id', $userIds)->delete();
                }
                if (Schema::hasTable('user_saved_posts')) {
                    DB::table('user_saved_posts')->whereIn('post_id', $postIds)->orWhereIn('user_id', $userIds)->delete();
                }
                if (Schema::hasTable('post_tags')) {
                    DB::table('post_tags')->whereIn('post_id', $postIds)->orWhereIn('user_id', $userIds)->delete();
                }
                Post::whereIn('id', $postIds)->delete();
            }

            // 3. Stories
            $storiesCount = 0;
            if (Schema::hasTable('stories')) {
                $storyIds = Story::whereIn('user_id', $userIds)->pluck('id')->all();
                $storiesCount = count($storyIds);
                if (! empty($storyIds)) {
                    if (Schema::hasTable('story_slides')) {
                        DB::table('story_slides')->whereIn('story_id', $storyIds)->delete();
                    }
                    if (Schema::hasTable('story_views')) {
                        DB::table('story_views')->whereIn('story_id', $storyIds)->orWhereIn('user_id', $userIds)->delete();
                    }
                    if (Schema::hasTable('story_replies')) {
                        DB::table('story_replies')->whereIn('story_id', $storyIds)->orWhereIn('user_id', $userIds)->delete();
                    }
                    if (Schema::hasTable('story_likes')) {
                        DB::table('story_likes')->whereIn('story_id', $storyIds)->orWhereIn('user_id', $userIds)->delete();
                    }
                    Story::whereIn('id', $storyIds)->delete();
                }
            }

            // 4. Reels vidéo
            $reelsCount = 0;
            if (Schema::hasTable('reels')) {
                $reelIds = Reel::whereIn('user_id', $userIds)->pluck('id')->all();
                $reelsCount = count($reelIds);
                if (! empty($reelIds)) {
                    if (Schema::hasTable('reel_comments')) {
                        DB::table('reel_comments')->whereIn('reel_id', $reelIds)->orWhereIn('user_id', $userIds)->delete();
                    }
                    if (Schema::hasTable('reel_reactions')) {
                        DB::table('reel_reactions')->whereIn('reel_id', $reelIds)->orWhereIn('user_id', $userIds)->delete();
                    }
                    Reel::whereIn('id', $reelIds)->delete();
                }
            }

            // 5. Pages professionnelles (ex: horizon_immo_page)
            $pagesCount = 0;
            if (Schema::hasTable('pages')) {
                $pageIds = Page::whereIn('owner_id', $userIds)
                    ->orWhere('handle', 'horizon_immo_page')
                    ->pluck('id')
                    ->all();
                $pagesCount = count($pageIds);
                if (! empty($pageIds)) {
                    if (Schema::hasTable('page_members')) {
                        DB::table('page_members')->whereIn('page_id', $pageIds)->orWhereIn('user_id', $userIds)->delete();
                    }
                    Page::whereIn('id', $pageIds)->delete();
                }
            }

            // 6. Abonnements et relations sociales
            if (Schema::hasTable('follows')) {
                DB::table('follows')->whereIn('follower_id', $userIds)->orWhereIn('following_id', $userIds)->delete();
            }
            if (Schema::hasTable('follow_requests')) {
                DB::table('follow_requests')->whereIn('from_id', $userIds)->orWhereIn('to_id', $userIds)->delete();
            }
            if (Schema::hasTable('user_blocks')) {
                DB::table('user_blocks')->whereIn('blocker_id', $userIds)->orWhereIn('blocked_id', $userIds)->delete();
            }
            if (Schema::hasTable('interest_user')) {
                DB::table('interest_user')->whereIn('user_id', $userIds)->delete();
            }

            // 7. Messagerie
            if (Schema::hasTable('messages')) {
                DB::table('messages')->whereIn('sender_id', $userIds)->delete();
            }
            if (Schema::hasTable('conversation_members')) {
                DB::table('conversation_members')->whereIn('user_id', $userIds)->delete();
            }

            // 8. Suppression définitive des utilisateurs
            $usersCount = count($userIds);
            User::whereIn('id', $userIds)->forceDelete();

            return [
                'users_deleted' => $usersCount,
                'posts_deleted' => $postsCount,
                'listings_deleted' => $listingsCount,
                'stories_deleted' => $storiesCount,
                'reels_deleted' => $reelsCount,
                'pages_deleted' => $pagesCount,
            ];
        });
    }
}
