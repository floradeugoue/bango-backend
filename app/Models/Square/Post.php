<?php

namespace App\Models\Square;

use App\Enums\Square\PostAudience;
use App\Enums\Square\PostCommentsBy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'posts';

    protected $fillable = [
        'user_id',
        'page_id',
        'body',
        'audience',
        'comments_by',
        'comments_closed',
        'allow_share',
        'hide_likes',
        'is_edited',
        'is_archived',
        'place_label',
        'place_detail',
        'place_city',
        'place_neighbourhood',
        'idempotency_key',
        'likes_count',
        'comments_count',
        'shares_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => PostAudience::class,
            'comments_by' => PostCommentsBy::class,
            'comments_closed' => 'boolean',
            'allow_share' => 'boolean',
            'hide_likes' => 'boolean',
            'is_edited' => 'boolean',
            'is_archived' => 'boolean',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
            'shares_count' => 'integer',
        ];
    }

    /**
     * Auteur de la publication.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Médias du carrousel de la publication (ordonnés).
     */
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('display_order', 'asc');
    }

    /**
     * Hashtags rattachés à la publication.
     */
    public function hashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'post_hashtag')->withTimestamps();
    }

    /**
     * Tous les tags de personnes identifiées sur les médias de ce post.
     */
    public function tags(): HasManyThrough
    {
        return $this->hasManyThrough(
            PostTag::class,
            PostMedia::class,
            'post_id',       // Clé étrangère sur post_media
            'post_media_id', // Clé étrangère sur post_tags
            'id',            // Clé locale sur posts
            'id'             // Clé locale sur post_media
        );
    }

    /**
     * Vérifie si l'utilisateur donné est l'auteur de la publication.
     */
    public function isAuthoredBy(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return (int) $this->user_id === (int) $userId;
    }

    /**
     * Scope pour les publications actives et non archivées (visibles dans les flux publics).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }
}
