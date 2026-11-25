<?php

namespace App\Services\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
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
            // 1. Annonces immobilières
            $listingIds = $this->idsWhereAny('listings', ['owner_id' => $userIds, 'created_by_id' => $userIds, 'user_id' => $userIds]);
            $this->deleteWhereAny('listing_media', ['listing_id' => $listingIds]);
            $this->deleteWhereAny('visit_slots', ['listing_id' => $listingIds, 'owner_id' => $userIds]);
            $this->deleteWhereAny('listing_shares', ['listing_id' => $listingIds, 'user_id' => $userIds]);
            $this->deleteWhereAny('listing_saves', ['listing_id' => $listingIds, 'user_id' => $userIds]);
            $this->deleteWhereAny('listing_likes', ['listing_id' => $listingIds, 'user_id' => $userIds]);
            $this->deleteWhereAny('listing_alerts', ['user_id' => $userIds]);
            $this->deleteWhereAny('listings', ['id' => $listingIds]);

            // 2. Le Square
            $postIds = $this->idsWhereAny('posts', ['user_id' => $userIds]);
            foreach (['post_media', 'post_comments', 'post_likes', 'post_shares', 'user_saved_posts', 'post_tags', 'post_reactions', 'post_views'] as $table) {
                $this->deleteWhereAny($table, [
                    'post_id' => $postIds,
                    'user_id' => $userIds,
                    'tagged_user_id' => $userIds,
                ]);
            }
            $this->deleteWhereAny('posts', ['id' => $postIds]);

            // 3. Stories
            $storyIds = $this->idsWhereAny('stories', ['user_id' => $userIds]);
            foreach (['story_slides', 'story_views', 'story_replies', 'story_likes', 'story_reactions'] as $table) {
                $this->deleteWhereAny($table, ['story_id' => $storyIds, 'user_id' => $userIds, 'viewer_id' => $userIds]);
            }
            $this->deleteWhereAny('stories', ['id' => $storyIds]);

            // 4. Reels
            $reelIds = $this->idsWhereAny('reels', ['user_id' => $userIds]);
            foreach (['reel_comments', 'reel_reactions', 'reel_likes', 'reel_views'] as $table) {
                $this->deleteWhereAny($table, ['reel_id' => $reelIds, 'user_id' => $userIds]);
            }
            $this->deleteWhereAny('reels', ['id' => $reelIds]);

            // 5. Pages professionnelles
            $pageIds = $this->idsWhereAny('pages', ['owner_id' => $userIds, 'handle' => ['horizon_immo_page']]);
            $this->deleteWhereAny('page_members', ['page_id' => $pageIds, 'user_id' => $userIds]);
            $this->deleteWhereAny('pages', ['id' => $pageIds]);

            // 6. Relations sociales
            $this->deleteWhereAny('follows', ['follower_id' => $userIds, 'following_id' => $userIds, 'followed_id' => $userIds]);
            $this->deleteWhereAny('follow_requests', ['from_id' => $userIds, 'to_id' => $userIds, 'requester_id' => $userIds, 'target_id' => $userIds]);
            $this->deleteWhereAny('user_blocks', ['blocker_id' => $userIds, 'blocked_id' => $userIds]);
            $this->deleteWhereAny('interest_user', ['user_id' => $userIds]);

            // 7. Messagerie
            $this->deleteWhereAny('messages', ['sender_id' => $userIds]);
            $this->deleteWhereAny('conversation_members', ['user_id' => $userIds]);

            // 8. Utilisateurs
            $usersCount = count($userIds);
            User::whereIn('id', $userIds)->forceDelete();

            return [
                'users_deleted' => $usersCount,
                'posts_deleted' => count($postIds),
                'listings_deleted' => count($listingIds),
                'stories_deleted' => count($storyIds),
                'reels_deleted' => count($reelIds),
                'pages_deleted' => count($pageIds),
            ];
        });
    }

    /**
     * Supprime les lignes dont au moins une colonne existante correspond.
     * Tables et colonnes absentes sont ignorées : aucune erreur SQL ne vient annuler la transaction PostgreSQL.
     *
     * @param  array<string, array<int, int|string>>  $conditions
     */
    private function deleteWhereAny(string $table, array $conditions): int
    {
        $query = $this->buildWhereAny($table, $conditions);

        return $query ? $query->delete() : 0;
    }

    /**
     * @param  array<string, array<int, int|string>>  $conditions
     * @return array<int, int>
     */
    private function idsWhereAny(string $table, array $conditions): array
    {
        $query = $this->buildWhereAny($table, $conditions);

        return $query ? $query->pluck('id')->all() : [];
    }

    /**
     * @param  array<string, array<int, int|string>>  $conditions
     */
    private function buildWhereAny(string $table, array $conditions): ?Builder
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $columns = Schema::getColumnListing($table);
        $usable = array_filter(
            $conditions,
            fn (array $values, string $column): bool => $values !== [] && in_array($column, $columns, true),
            ARRAY_FILTER_USE_BOTH
        );

        if ($usable === []) {
            return null;
        }

        return DB::table($table)->where(function (Builder $query) use ($usable): void {
            foreach ($usable as $column => $values) {
                $query->orWhereIn($column, $values);
            }
        });
    }
}
