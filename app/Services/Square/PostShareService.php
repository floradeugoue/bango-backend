<?php

namespace App\Services\Square;

use App\Models\Square\Post;
use App\Models\Square\PostShare;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PostShareService
{
    /**
     * Enregistre un partage de publication de manière atomique et protégée contre les doublons.
     *
     * @return array{
     *     shared: bool,
     *     shares_count: int,
     *     share_url: string,
     *     deep_link: string,
     *     post_id: int
     * }
     *
     * @throws AuthorizationException
     */
    public function share(Post $post, User $user, ?string $channel = null, ?string $idempotencyKey = null): array
    {
        if (Gate::forUser($user)->denies('share', $post)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à partager cette publication ou les partages sont désactivés.');
        }

        return DB::transaction(function () use ($post, $user, $channel, $idempotencyKey) {
            /** @var Post $lockedPost */
            $lockedPost = Post::where('id', $post->id)->lockForUpdate()->firstOrFail();

            $existing = null;
            if ($idempotencyKey) {
                $existing = PostShare::where('post_id', $lockedPost->id)
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
            }

            if (! $existing) {
                PostShare::create([
                    'post_id' => $lockedPost->id,
                    'user_id' => $user->id,
                    'channel' => $channel,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $lockedPost->increment('shares_count');
                $lockedPost->refresh();
            }

            return [
                'shared' => true,
                'shares_count' => (int) $lockedPost->shares_count,
                'share_url' => $lockedPost->shareUrl(),
                'deep_link' => "bango://post/{$lockedPost->id}",
                'post_id' => $lockedPost->id,
            ];
        });
    }
}
