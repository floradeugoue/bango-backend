<?php

namespace App\Services\Sync;

use App\Enums\Reel\ReelReactionType;
use App\Models\Listing\Listing;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Listing\ListingService;
use App\Services\Reel\ReelInteractionService;
use App\Services\Square\PostLikeService;
use Throwable;

class OfflineSyncService
{
    public function __construct(
        protected PostLikeService $postLikeService,
        protected ReelInteractionService $reelInteractionService,
        protected ListingService $listingService
    ) {}

    /**
     * Traite un lot d'actions hors-ligne avec isolation des erreurs et idempotence.
     *
     * @param  array<int, array{
     *     action_id: string,
     *     type: string,
     *     target_id: string,
     *     payload?: array<string, mixed>|null,
     *     occurred_at?: string|null
     * }>  $actions
     * @return array{
     *     processed_count: int,
     *     success_count: int,
     *     failed_count: int,
     *     results: array<int, array{
     *         action_id: string,
     *         type: string,
     *         target_id: string,
     *         status: string,
     *         error: ?string
     *     }>
     * }
     */
    public function flush(User $user, array $actions, ?string $clientIp = null): array
    {
        $results = [];
        $processedActionIds = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($actions as $action) {
            $actionId = $action['action_id'];
            $type = $action['type'];
            $targetId = $action['target_id'];

            // Déduplication intra-lot immédiate
            if (isset($processedActionIds[$actionId])) {
                $results[] = [
                    'action_id' => $actionId,
                    'type' => $type,
                    'target_id' => $targetId,
                    'status' => 'already_applied',
                    'error' => null,
                ];
                $successCount++;

                continue;
            }

            $processedActionIds[$actionId] = true;

            try {
                $status = $this->applyAction($user, $type, $targetId, $action['payload'] ?? [], $clientIp);
                $results[] = [
                    'action_id' => $actionId,
                    'type' => $type,
                    'target_id' => $targetId,
                    'status' => $status,
                    'error' => null,
                ];
                $successCount++;
            } catch (Throwable $e) {
                $failedCount++;
                $results[] = [
                    'action_id' => $actionId,
                    'type' => $type,
                    'target_id' => $targetId,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'processed_count' => count($results),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * Applique une action spécifique de manière sécurisée.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function applyAction(User $user, string $type, string $targetId, array $payload, ?string $clientIp): string
    {
        return match ($type) {
            'like_post' => $this->handleLikePost($user, $targetId),
            'unlike_post' => $this->handleUnlikePost($user, $targetId),
            'like_reel' => $this->handleLikeReel($user, $targetId),
            'save_reel' => $this->handleSaveReel($user, $targetId),
            'view_reel' => $this->handleViewReel($user, $targetId, $clientIp),
            'bookmark_listing' => $this->handleBookmarkListing($user, $targetId, 'save'),
            'unbookmark_listing' => $this->handleBookmarkListing($user, $targetId, 'unsave'),
            'follow_user' => $this->handleFollowUser($user, $targetId),
            'unfollow_user' => $this->handleUnfollowUser($user, $targetId),
            default => throw new \InvalidArgumentException("Action '{$type}' non reconnue."),
        };
    }

    protected function handleLikePost(User $user, string $postId): string
    {
        $post = Post::find($postId);
        if (! $post) {
            throw new \RuntimeException('Publication Square introuvable.');
        }

        $result = $this->postLikeService->like($post, $user);

        return $result['liked'] ? 'success' : 'already_applied';
    }

    protected function handleUnlikePost(User $user, string $postId): string
    {
        $post = Post::find($postId);
        if (! $post) {
            throw new \RuntimeException('Publication Square introuvable.');
        }

        $this->postLikeService->unlike($post, $user);

        return 'success';
    }

    protected function handleLikeReel(User $user, string $reelId): string
    {
        $reel = Reel::find($reelId);
        if (! $reel) {
            throw new \RuntimeException('Reel introuvable.');
        }

        $this->reelInteractionService->react($reel, $user, ReelReactionType::Like);

        return 'success';
    }

    protected function handleSaveReel(User $user, string $reelId): string
    {
        $reel = Reel::find($reelId);
        if (! $reel) {
            throw new \RuntimeException('Reel introuvable.');
        }

        $this->reelInteractionService->toggleSave($reel, $user);

        return 'success';
    }

    protected function handleViewReel(User $user, string $reelId, ?string $clientIp): string
    {
        $reel = Reel::find($reelId);
        if (! $reel) {
            throw new \RuntimeException('Reel introuvable.');
        }

        $result = $this->reelInteractionService->recordView($reel, $user, $clientIp);

        return ($result['viewed'] ?? false) ? 'success' : 'already_applied';
    }

    protected function handleBookmarkListing(User $user, string $listingId, string $action): string
    {
        $listing = Listing::find($listingId);
        if (! $listing) {
            throw new \RuntimeException('Annonce immobilière introuvable.');
        }

        $this->listingService->saveListing($listing, $user, $action);

        return 'success';
    }

    protected function handleFollowUser(User $user, string $targetUserId): string
    {
        $target = User::find($targetUserId);
        if (! $target) {
            throw new \RuntimeException('Utilisateur introuvable.');
        }

        if ((int) $user->id === (int) $target->id) {
            throw new \RuntimeException('Vous ne pouvez pas vous suivre vous-même.');
        }

        if ($user->isFollowing($target)) {
            return 'already_applied';
        }

        $user->following()->syncWithoutDetaching([$target->id]);

        return 'success';
    }

    protected function handleUnfollowUser(User $user, string $targetUserId): string
    {
        $target = User::find($targetUserId);
        if (! $target) {
            throw new \RuntimeException('Utilisateur introuvable.');
        }

        $user->following()->detach($target->id);

        return 'success';
    }
}
