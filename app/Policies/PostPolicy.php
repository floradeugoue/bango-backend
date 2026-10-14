<?php

namespace App\Policies;

use App\Enums\Square\PostAudience;
use App\Models\Profile\Follow;
use App\Models\Square\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Détermine si l'utilisateur peut voir la publication.
     */
    public function view(?User $user, Post $post): bool
    {
        // Un post archivé n'est visible que par son auteur
        if ($post->is_archived) {
            return $user !== null && (int) $post->user_id === (int) $user->id;
        }

        // L'auteur peut toujours voir ses propres publications
        if ($user && (int) $post->user_id === (int) $user->id) {
            return true;
        }

        // Audience publique
        if ($post->audience === PostAudience::Public) {
            return true;
        }

        // Si l'utilisateur n'est pas authentifié, il ne peut pas voir un post restreint
        if (! $user) {
            return false;
        }

        // Audience 'followers'
        if ($post->audience === PostAudience::Followers) {
            return Follow::where('follower_id', $user->id)
                ->where('following_id', $post->user_id)
                ->exists();
        }

        // Audience 'neighbourhood'
        if ($post->audience === PostAudience::Neighbourhood) {
            if ($post->place_neighbourhood && $user->neighbourhood && $post->place_neighbourhood === $user->neighbourhood) {
                return true;
            }
            if ($post->place_city && $user->city && $post->place_city === $user->city) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seul l'auteur peut modifier la publication (légende, audience, commentaires).
     */
    public function update(User $user, Post $post): bool
    {
        return (int) $post->user_id === (int) $user->id;
    }

    /**
     * Seul l'auteur peut archiver ou désarchiver.
     */
    public function archive(User $user, Post $post): bool
    {
        return (int) $post->user_id === (int) $user->id;
    }

    /**
     * Seul l'auteur peut supprimer la publication.
     */
    public function delete(User $user, Post $post): bool
    {
        return (int) $post->user_id === (int) $user->id;
    }
}
