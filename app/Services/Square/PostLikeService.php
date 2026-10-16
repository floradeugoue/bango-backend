<?php

namespace App\Services\Square;

use App\Models\Square\Post;
use App\Models\Square\PostLike;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PostLikeService
{
    /**
     * Aime une publication de manière atomique et idempotente.
     *
     * @return array{liked: bool, likes_count: int}
     *
     * @throws AuthorizationException
     */
    public function like(Post $post, User $user): array
    {
        if (Gate::forUser($user)->denies('view', $post)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à interagir avec cette publication.');
        }

        return DB::transaction(function () use ($post, $user) {
            $like = PostLike::firstOrCreate(
                ['post_id' => $post->id, 'user_id' => $user->id]
            );

            if ($like->wasRecentlyCreated) {
                Post::where('id', $post->id)->increment('likes_count');
            }

            $currentCount = (int) Post::where('id', $post->id)->value('likes_count');

            return [
                'liked' => true,
                'likes_count' => $currentCount,
            ];
        });
    }

    /**
     * Retire le like d'une publication de manière atomique et idempotente.
     *
     * @return array{liked: bool, likes_count: int}
     *
     * @throws AuthorizationException
     */
    public function unlike(Post $post, User $user): array
    {
        if (Gate::forUser($user)->denies('view', $post)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à interagir avec cette publication.');
        }

        return DB::transaction(function () use ($post, $user) {
            $deleted = PostLike::where('post_id', $post->id)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted > 0) {
                Post::where('id', $post->id)
                    ->where('likes_count', '>', 0)
                    ->decrement('likes_count');
            }

            $currentCount = (int) Post::where('id', $post->id)->value('likes_count');

            return [
                'liked' => false,
                'likes_count' => $currentCount,
            ];
        });
    }

    /**
     * Récupère la liste paginée et déterministe des utilisateurs ayant aimé une publication.
     * Respecte la visibilité du post, la confidentialité et les blocages mutuels.
     *
     * @throws AuthorizationException
     */
    public function getPostLikers(Post $post, ?User $currentUser = null, int $perPage = 20): LengthAwarePaginator
    {
        if (Gate::forUser($currentUser)->denies('view', $post)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à consulter cette publication.');
        }

        $perPage = min(max($perPage, 1), 100);

        $query = User::query()
            ->join('post_likes', 'users.id', '=', 'post_likes.user_id')
            ->where('post_likes.post_id', $post->id)
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at');

        if ($currentUser) {
            $query->whereNotExists(function ($q) use ($currentUser) {
                $q->select(DB::raw(1))
                    ->from('user_blocks')
                    ->where(function ($sub) use ($currentUser) {
                        $sub->where('user_blocks.blocker_id', $currentUser->id)
                            ->whereColumn('user_blocks.blocked_id', 'users.id');
                    })
                    ->orWhere(function ($sub) use ($currentUser) {
                        $sub->whereColumn('user_blocks.blocker_id', 'users.id')
                            ->where('user_blocks.blocked_id', $currentUser->id);
                    });
            });
        }

        return $query->select('users.*', 'post_likes.created_at as liked_at')
            ->orderByDesc('post_likes.created_at')
            ->orderByDesc('users.id')
            ->paginate($perPage);
    }
}
