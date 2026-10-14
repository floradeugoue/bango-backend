<?php

namespace App\Models\Square;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PostComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'post_comments';

    protected $fillable = [
        'post_id',
        'user_id',
        'parent_id',
        'body',
        'likes_count',
        'dislikes_count',
        'replies_count',
        'is_edited',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'likes_count' => 'integer',
            'dislikes_count' => 'integer',
            'replies_count' => 'integer',
            'is_edited' => 'boolean',
        ];
    }

    /**
     * Publication parente du commentaire.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Auteur du commentaire.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Commentaire parent dans le cas d'une réponse.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Réponses directes à ce commentaire.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at', 'asc');
    }

    /**
     * Toutes les réactions (likes / dislikes) sur ce commentaire.
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(PostCommentReaction::class, 'comment_id');
    }

    /**
     * Vérifie si ce commentaire est une réponse directe à un autre commentaire.
     */
    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Vérifie si l'utilisateur donné est l'auteur du commentaire.
     */
    public function isAuthoredBy(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return (int) $this->user_id === (int) $userId;
    }

    /**
     * Renvoie le type de réaction de l'utilisateur donné ('like', 'dislike', ou null).
     */
    public function reactionOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ($this->relationLoaded('reactions')) {
            $reaction = $this->reactions->firstWhere('user_id', $user->id);

            return $reaction?->type;
        }

        return $this->reactions()->where('user_id', $user->id)->value('type');
    }
}
