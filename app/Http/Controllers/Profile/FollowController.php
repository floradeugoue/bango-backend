<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\FollowUserResource;
use App\Models\Profile\FollowRequest;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use App\Services\Boost\CampaignAttributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FollowController extends Controller
{
    /**
     * Follow a user or request to follow if private.
     */
    public function follow(Request $request, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::findOrFail($id);

        if ($actor->id === $target->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas vous suivre vous-même.',
            ], 422);
        }

        if ($actor->hasBlocked($target) || $actor->isBlockedBy($target)) {
            return response()->json([
                'message' => 'Action impossible. Cet utilisateur est bloqué.',
            ], 403);
        }

        if ($actor->isFollowing($target)) {
            return response()->json([
                'status' => 'already_following',
            ], 200);
        }

        // PR-08: If target account is private, create a follow request
        if ($target->is_private) {
            FollowRequest::firstOrCreate(
                ['from_id' => $actor->id, 'to_id' => $target->id],
                ['status' => 'pending']
            );

            $target->notify(new SocialNotification('follow_request', $actor));

            return response()->json([
                'status' => 'requested',
            ], 200);
        }

        // Public account: directly follow
        FollowRequest::where('from_id', $actor->id)->where('to_id', $target->id)->delete();
        $actor->following()->syncWithoutDetaching([$target->id]);

        $target->notify(new SocialNotification('follow', $actor));

        // Attribution publicitaire automatique (Boost) si impression < 24h
        app(CampaignAttributionService::class)->attributeFollow($actor, $target);

        return response()->json([
            'status' => 'followed',
        ], 200);
    }

    /**
     * Unfollow a user or withdraw pending follow request.
     */
    public function unfollow(Request $request, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::findOrFail($id);

        $wasFollowing = $actor->isFollowing($target);
        $wasRequested = $actor->hasRequestedToFollow($target);

        if ($wasFollowing) {
            $actor->following()->detach($target->id);
            $status = 'unfollowed';
        } elseif ($wasRequested) {
            $actor->sentFollowRequests()->where('to_id', $target->id)->delete();
            $status = 'withdrawn';
        } else {
            $status = 'unchanged';
        }

        return response()->json([
            'status' => $status,
        ], 200);
    }

    /**
     * Toggle follow state (used by mobile toggleFollow).
     */
    public function toggle(Request $request, string $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = User::findOrFail($id);

        if ($actor->isFollowing($target) || $actor->hasRequestedToFollow($target)) {
            return $this->unfollow($request, $id);
        }

        return $this->follow($request, $id);
    }

    /**
     * List followers of authenticated user or specified user.
     */
    public function followers(Request $request, ?string $id = null): AnonymousResourceCollection|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = $id ? User::findOrFail($id) : $actor;

        if ($actor->id !== $target->id && ! $actor->canViewContentOf($target)) {
            return response()->json(['message' => 'Ce compte est privé ou inaccessible.'], 403);
        }

        $query = $target->followers();

        if ($request->filled('query')) {
            $needle = '%'.strtolower($request->query('query')).'%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(display_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(handle) LIKE ?', [$needle]);
            });
        }

        $perPage = (int) $request->input('per_page', 20);

        return FollowUserResource::collection($query->paginate($perPage));
    }

    /**
     * List following of authenticated user or specified user.
     */
    public function following(Request $request, ?string $id = null): AnonymousResourceCollection|JsonResponse
    {
        /** @var User|null $actor */
        $actor = $request->user();
        /** @var User $target */
        $target = $id ? User::findOrFail($id) : $actor;

        if ($target && (! $actor || (int) $actor->id !== (int) $target->id)) {
            $showFollowing = (bool) data_get($target->privacy_settings, 'showFollowing', true);
            if (! $showFollowing) {
                return response()->json([
                    'kind' => 'following_hidden',
                    'message' => 'La liste des abonnements de cet utilisateur est masquée.',
                ], 403);
            }
        }

        if ($actor && $actor->id !== $target->id && ! $actor->canViewContentOf($target)) {
            return response()->json(['message' => 'Ce compte est privé ou inaccessible.'], 403);
        }

        $query = $target->following();

        if ($request->filled('query')) {
            $needle = '%'.strtolower($request->query('query')).'%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(display_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(handle) LIKE ?', [$needle]);
            });
        }

        $perPage = (int) $request->input('per_page', 20);

        return FollowUserResource::collection($query->paginate($perPage));
    }
}
