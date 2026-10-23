<?php

namespace App\Services\Square;

use App\Models\Square\Post;
use App\Models\Square\PostView;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PostViewService
{
    /**
     * Enregistre une vue : au plus une par compte et par fenêtre de 24 h.
     *
     * @return array{viewed: bool, views_count: int}
     */
    public function recordView(Post $post, User $user): array
    {
        return DB::transaction(function () use ($post, $user) {
            $locked = Post::query()->lockForUpdate()->findOrFail($post->id);

            $alreadyViewed = PostView::query()
                ->where('post_id', $locked->id)
                ->where('user_id', $user->id)
                ->where('viewed_at', '>=', now()->subHours(24))
                ->exists();

            if ($alreadyViewed) {
                return ['viewed' => false, 'views_count' => (int) $locked->views_count];
            }

            PostView::create([
                'post_id' => $locked->id,
                'user_id' => $user->id,
                'viewed_at' => now(),
            ]);

            $locked->increment('views_count');

            return ['viewed' => true, 'views_count' => (int) $locked->views_count];
        });
    }
}
