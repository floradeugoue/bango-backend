<?php

namespace App\Services\Square;

use App\Models\Square\Post;
use App\Models\Square\PostLike;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
}
