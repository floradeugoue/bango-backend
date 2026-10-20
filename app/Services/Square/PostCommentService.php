<?php

namespace App\Services\Square;

use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use App\Services\Admin\AutomatedTextFilterService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PostCommentService
{
    /**
     * Récupère la liste paginée des commentaires de premier niveau d'une publication.
     *
     * @throws AuthorizationException
     */
    public function getComments(Post $post, ?User $user, int $perPage = 20): LengthAwarePaginator
    {
        if (Gate::forUser($user)->denies('view', $post)) {
            throw new AuthorizationException('Vous n\'avez pas accès aux commentaires de cette publication.');
        }

        $query = PostComment::query()
            ->where('post_id', $post->id)
            ->whereNull('parent_id')
            ->with(['user'])
            ->orderBy('created_at', 'desc');

        if ($user) {
            $query->with(['reactions' => fn ($q) => $q->where('user_id', $user->id)]);
        }

        return $query->paginate(min(max($perPage, 1), 50));
    }

    /**
     * Récupère la liste paginée des réponses directes à un commentaire.
     *
     * @throws AuthorizationException
     */
    public function getReplies(PostComment $comment, ?User $user, int $perPage = 20): LengthAwarePaginator
    {
        if (Gate::forUser($user)->denies('view', $comment->post)) {
            throw new AuthorizationException('Vous n\'avez pas accès à ce fil de discussion.');
        }

        $query = PostComment::query()
            ->where('parent_id', $comment->id)
            ->with(['user'])
            ->orderBy('created_at', 'asc');

        if ($user) {
            $query->with(['reactions' => fn ($q) => $q->where('user_id', $user->id)]);
        }

        return $query->paginate(min(max($perPage, 1), 50));
    }

    /**
     * Crée un commentaire ou une réponse sur une publication.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function createComment(Post $post, User $user, string $body, ?int $parentId = null): PostComment
    {
        if (Gate::forUser($user)->denies('create', [PostComment::class, $post])) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à commenter cette publication.');
        }

        $parent = null;
        if ($parentId) {
            /** @var PostComment|null $parent */
            $parent = PostComment::where('id', $parentId)
                ->where('post_id', $post->id)
                ->first();

            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Le commentaire parent spécifié est introuvable sur cette publication.'],
                ]);
            }

            // Limitation de la profondeur : 1 seul niveau de réponses
            if ($parent->parent_id !== null) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Impossible de répondre à une réponse. La profondeur maximale est limitée à 1 niveau.'],
                ]);
            }
        }

        return DB::transaction(function () use ($post, $user, $body, $parentId, $parent) {
            $comment = PostComment::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'body' => $body,
                'likes_count' => 0,
                'dislikes_count' => 0,
                'replies_count' => 0,
                'is_edited' => false,
            ]);

            // Incrémentation atomique du compteur global de la publication
            Post::where('id', $post->id)->increment('comments_count');

            // Si c'est une réponse, incrémentation du compteur de réponses du parent
            if ($parentId) {
                PostComment::where('id', $parentId)->increment('replies_count');
            }

            // Point 19 : Déclenchement du filtre textuel automatique
            app(AutomatedTextFilterService::class)
                ->scanText($body, 'comment', $comment->id, $user);

            // Notifications sociales : commentaire, réponse et mentions
            if ($parentId && $parent && $parent->user_id !== $user->id) {
                $parentAuthor = User::find($parent->user_id);
                if ($parentAuthor) {
                    $parentAuthor->notify(new SocialNotification('reply', $user, [
                        'post_id' => $post->id,
                        'comment_id' => $comment->id,
                        'text' => $body,
                    ]));
                }
            } elseif (! $parentId && $post->user_id && $post->user_id !== $user->id) {
                $postAuthor = User::find($post->user_id);
                if ($postAuthor) {
                    $postAuthor->notify(new SocialNotification('comment', $user, [
                        'post_id' => $post->id,
                        'comment_id' => $comment->id,
                        'text' => $body,
                    ]));
                }
            }

            // Mentions (@handle)
            if (preg_match_all('/@([a-zA-Z0-9_.]+)/', $body, $matches) && ! empty($matches[1])) {
                $mentionedUsers = User::whereIn('handle', $matches[1])
                    ->where('id', '!=', $user->id)
                    ->get();
                foreach ($mentionedUsers as $mentioned) {
                    $mentioned->notify(new SocialNotification('mention', $user, [
                        'post_id' => $post->id,
                        'comment_id' => $comment->id,
                        'text' => $body,
                    ]));
                }
            }

            return $comment->load('user');
        });
    }

    /**
     * Modifie un commentaire existant.
     *
     * @throws AuthorizationException
     */
    public function updateComment(PostComment $comment, User $user, string $newBody): PostComment
    {
        if (Gate::forUser($user)->denies('update', $comment)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à modifier ce commentaire.');
        }

        $comment->update([
            'body' => $newBody,
            'is_edited' => true,
        ]);

        return $comment->fresh('user');
    }

    /**
     * Supprime un commentaire et ses éventuelles réponses.
     *
     * @throws AuthorizationException
     */
    public function deleteComment(PostComment $comment, User $user): bool
    {
        if (Gate::forUser($user)->denies('delete', $comment)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à supprimer ce commentaire.');
        }

        return DB::transaction(function () use ($comment) {
            $postId = $comment->post_id;
            $parentId = $comment->parent_id;

            if ($parentId === null) {
                // Commentaire de premier niveau : suppression en cascade des réponses
                $repliesCount = $comment->replies()->count();
                $comment->replies()->delete();
                $comment->delete();

                $totalDeleted = 1 + $repliesCount;
                Post::where('id', $postId)
                    ->where('comments_count', '>=', $totalDeleted)
                    ->decrement('comments_count', $totalDeleted);
            } else {
                // Réponse directe
                $comment->delete();

                // Décrémenter les réponses du parent
                PostComment::where('id', $parentId)
                    ->where('replies_count', '>', 0)
                    ->decrement('replies_count');

                // Décrémenter le compteur de la publication
                Post::where('id', $postId)
                    ->where('comments_count', '>', 0)
                    ->decrement('comments_count');
            }

            return true;
        });
    }
}
