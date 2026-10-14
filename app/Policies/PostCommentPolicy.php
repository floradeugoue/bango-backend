<?php

namespace App\Policies;

use App\Enums\Square\PostCommentsBy;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\User;

class PostCommentPolicy
{
    public function __construct(
        protected PostPolicy $postPolicy
    ) {}

    /**
     * Détermine si l'utilisateur peut voir un commentaire.
     */
    public function view(?User $user, PostComment $comment): bool
    {
        return $this->postPolicy->view($user, $comment->post);
    }

    /**
     * Détermine si l'utilisateur peut commenter ou répondre sur une publication.
     */
    public function create(User $user, Post $post): bool
    {
        // L'utilisateur doit pouvoir voir la publication
        if (! $this->postPolicy->view($user, $post)) {
            return false;
        }

        // Si la publication est archivée, pas de nouveaux commentaires
        if ($post->is_archived) {
            return false;
        }

        // Si les commentaires sont fermés par l'auteur
        if ($post->comments_closed) {
            return false;
        }

        // L'auteur peut toujours commenter son propre post
        if ((int) $post->user_id === (int) $user->id) {
            return true;
        }

        // Vérification des restrictions de commentaires (comments_by)
        $commentsBy = $post->comments_by instanceof PostCommentsBy
            ? $post->comments_by
            : PostCommentsBy::tryFrom((string) $post->comments_by);

        if ($commentsBy === PostCommentsBy::Nobody) {
            return false;
        }

        if ($commentsBy === PostCommentsBy::Followers) {
            return $user->isFollowing($post->user);
        }

        return true;
    }

    /**
     * Seul l'auteur peut modifier son commentaire.
     */
    public function update(User $user, PostComment $comment): bool
    {
        if ($comment->trashed()) {
            return false;
        }

        return (int) $comment->user_id === (int) $user->id;
    }

    /**
     * L'auteur du commentaire OU l'auteur de la publication peut supprimer un commentaire.
     */
    public function delete(User $user, PostComment $comment): bool
    {
        if ((int) $comment->user_id === (int) $user->id) {
            return true;
        }

        return (int) $comment->post->user_id === (int) $user->id;
    }

    /**
     * Détermine si l'utilisateur peut réagir (like / dislike) à un commentaire.
     */
    public function react(User $user, PostComment $comment): bool
    {
        if ($comment->trashed()) {
            return false;
        }

        return $this->view($user, $comment);
    }
}
