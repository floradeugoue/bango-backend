<?php

namespace App\Services\Square;

use App\Enums\Square\PostAudience;
use App\Enums\Square\PostCommentsBy;
use App\Models\Square\Post;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PostManagementService
{
    public function __construct(
        protected HashtagExtractionService $hashtagService
    ) {}

    /**
     * Met à jour la légende d'un post (max 2 200 caractères) et marque is_edited = true.
     */
    public function updateCaption(Post $post, string $newCaption): Post
    {
        $trimmed = trim($newCaption);

        if (mb_strlen($trimmed) > 2200) {
            throw new InvalidArgumentException('La légende ne peut pas dépasser 2 200 caractères.');
        }

        // Si le post n'a aucun média et que le texte devient vide, interdire
        if ($trimmed === '' && $post->media()->count() === 0) {
            throw new InvalidArgumentException('Une publication sans média ne peut pas avoir un texte vide.');
        }

        return DB::transaction(function () use ($post, $trimmed) {
            $post->update([
                'body' => $trimmed !== '' ? $trimmed : null,
                'is_edited' => true,
            ]);

            $this->hashtagService->syncPostHashtags($post, $trimmed);

            return $post->load(['media.tags.user', 'hashtags', 'user']);
        });
    }

    /**
     * Met à jour l'audience de la publication.
     */
    public function updateAudience(Post $post, PostAudience|string $audience): Post
    {
        $val = $audience instanceof PostAudience ? $audience->value : $audience;

        if (! in_array($val, PostAudience::values(), true)) {
            throw new InvalidArgumentException("Audience invalide ({$val}).");
        }

        $post->update(['audience' => $val]);

        return $post;
    }

    /**
     * Met à jour le statut des commentaires.
     */
    public function updateCommentsStatus(Post $post, ?bool $closed = null, PostCommentsBy|string|null $by = null): Post
    {
        $updates = [];

        if ($closed !== null) {
            $updates['comments_closed'] = $closed;
        }

        if ($by !== null) {
            $byVal = $by instanceof PostCommentsBy ? $by->value : $by;
            if (! in_array($byVal, PostCommentsBy::values(), true)) {
                throw new InvalidArgumentException("Option de commentaires invalide ({$byVal}).");
            }
            $updates['comments_by'] = $byVal;
        }

        if (! empty($updates)) {
            $post->update($updates);
        }

        return $post;
    }

    /**
     * Archive un post (le masque des flux publics).
     */
    public function archive(Post $post): Post
    {
        $post->update(['is_archived' => true]);

        return $post;
    }

    /**
     * Désarchive un post (le rend de nouveau visible dans les flux publics).
     */
    public function unarchive(Post $post): Post
    {
        $post->update(['is_archived' => false]);

        return $post;
    }

    /**
     * Supprime un post de façon sécurisée en nettoyant les compteurs de hashtags.
     */
    public function delete(Post $post): bool
    {
        return DB::transaction(function () use ($post) {
            $this->hashtagService->detachAll($post);

            return (bool) $post->delete();
        });
    }
}
