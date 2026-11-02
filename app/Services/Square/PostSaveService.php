<?php

namespace App\Services\Square;

use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PostSaveService
{
    /**
     * Bascule l'enregistrement d'une publication pour l'utilisateur ou la page active.
     *
     * @return array{saved: bool}
     */
    public function toggleSave(Post $post, User $user): array
    {
        $activePage = request()?->attributes?->get('active_page');

        if ($activePage) {
            $record = DB::table('user_saved_posts')
                ->where('post_id', $post->id)
                ->where('page_id', $activePage->id)
                ->first();

            if ($record) {
                DB::table('user_saved_posts')
                    ->where('id', $record->id)
                    ->delete();

                $post->decrement('saves_count');

                return [
                    'saved' => false,
                    'saves_count' => max(0, (int) $post->fresh()->saves_count),
                ];
            }

            DB::table('user_saved_posts')->insert([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'page_id' => $activePage->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $post->increment('saves_count');

            return [
                'saved' => true,
                'saves_count' => (int) $post->fresh()->saves_count,
            ];
        }

        $record = DB::table('user_saved_posts')
            ->where('post_id', $post->id)
            ->where('user_id', $user->id)
            ->whereNull('page_id')
            ->first();

        if ($record) {
            DB::table('user_saved_posts')
                ->where('id', $record->id)
                ->delete();

            $post->decrement('saves_count');

            return [
                'saved' => false,
                'saves_count' => max(0, (int) $post->fresh()->saves_count),
            ];
        }

        DB::table('user_saved_posts')->insert([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'page_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $post->increment('saves_count');

        return [
            'saved' => true,
            'saves_count' => (int) $post->fresh()->saves_count,
        ];
    }

    /**
     * Liste paginée des publications enregistrées par l'utilisateur connecté ou sa page active.
     */
    public function listSavedPosts(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $activePage = request()?->attributes?->get('active_page');

        $query = Post::query()
            ->with(['media.tags.user', 'hashtags', 'user', 'page']);

        if ($activePage) {
            return $query
                ->join('user_saved_posts', 'posts.id', '=', 'user_saved_posts.post_id')
                ->where('user_saved_posts.page_id', $activePage->id)
                ->select('posts.*')
                ->orderByDesc('user_saved_posts.created_at')
                ->paginate($perPage);
        }

        return $query
            ->join('user_saved_posts', 'posts.id', '=', 'user_saved_posts.post_id')
            ->where('user_saved_posts.user_id', $user->id)
            ->whereNull('user_saved_posts.page_id')
            ->select('posts.*')
            ->orderByDesc('user_saved_posts.created_at')
            ->paginate($perPage);
    }
}
