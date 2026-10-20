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
        // Une publication d'un auteur suspendu n'est plus accessible
        $author = $post->relationLoaded('user') ? $post->user : User::find($post->user_id);
        if ($author && ($author->status === 'suspended' || (method_exists($author, 'isSuspended') && $author->isSuspended()))) {
            return false;
        }

        // Un post archivé n'est visible que par son auteur
        if ($post->is_archived) {
            return $user !== null && (int) $post->user_id === (int) $user->id;
        }

        // L'auteur peut toujours voir ses propres publications
        if ($user && (int) $post->user_id === (int) $user->id) {
            return true;
        }

        // Si l'un des deux utilisateurs a bloqué l'autre, l'accès est refusé
        if ($user && ($user->hasBlocked($post->user_id) || $user->isBlockedBy($post->user_id))) {
            return false;
        }

        // Audience publique
        if ($post->audience === PostAudience::Public || $post->audience === 'public') {
            return true;
        }

        // Si l'utilisateur n'est pas authentifié, il ne peut pas voir un post restreint
        if (! $user) {
            return false;
        }

        // Audience 'followers'
        if ($post->audience === PostAudience::Followers || $post->audience === 'followers') {
            return Follow::where('follower_id', $user->id)
                ->where('following_id', $post->user_id)
                ->exists();
        }

        // Audience 'neighbourhood'
        if ($post->audience === PostAudience::Neighbourhood || $post->audience === 'neighbourhood') {
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

    /**
     * Détermine si l'utilisateur peut partager la publication.
     */
    public function share(User $user, Post $post): bool
    {
        if (! $this->view($user, $post)) {
            return false;
        }

        if ($post->is_archived) {
            return false;
        }

        return (bool) $post->allow_share;
    }
}
