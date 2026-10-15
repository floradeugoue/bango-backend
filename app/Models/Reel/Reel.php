<?php

namespace App\Models\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Enums\Reel\ReelStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reel extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'sound_id',
        'caption',
        'topic',
        'status',
        'error_message',
        'video_url',
        'thumbnail_url',
        'hls_url',
        'stream_media_id',
        'duration',
        'width',
        'height',
        'aspect_ratio',
        'allow_download',
        'comments_closed',
        'subtitles',
        'subtitles_locale',
        'composition',
        'renditions',
        'listing_id',
        'views_count',
        'likes_count',
        'dislikes_count',
        'comments_count',
        'shares_count',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReelStatus::class,
            'duration' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'allow_download' => 'boolean',
            'comments_closed' => 'boolean',
            'composition' => 'array',
            'renditions' => 'array',
            'views_count' => 'integer',
            'likes_count' => 'integer',
            'dislikes_count' => 'integer',
            'comments_count' => 'integer',
            'shares_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Auteur du Reel.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Son associé au Reel.
     */
    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class, 'sound_id');
    }

    /**
     * Réactions (likes et dislikes) sur ce Reel.
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(ReelReaction::class);
    }

    /**
     * Likes uniquement sur ce Reel.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(ReelReaction::class)->where('type', ReelReactionType::Like->value);
    }

    /**
     * Dislikes uniquement sur ce Reel.
     */
    public function dislikes(): HasMany
    {
        return $this->hasMany(ReelReaction::class)->where('type', ReelReactionType::Dislike->value);
    }

    /**
     * Commentaires de premier niveau.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ReelComment::class)->whereNull('parent_id')->orderByDesc('created_at');
    }

    /**
     * Tous les commentaires (y compris les réponses).
     */
    public function allComments(): HasMany
    {
        return $this->hasMany(ReelComment::class)->orderByDesc('created_at');
    }

    /**
     * Partages du Reel.
     */
    public function shares(): HasMany
    {
        return $this->hasMany(ReelShare::class);
    }

    /**
     * Vues enregistrées du Reel.
     */
    public function views(): HasMany
    {
        return $this->hasMany(ReelView::class);
    }

    /**
     * Utilisateurs ayant sauvegardé ce Reel.
     */
    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_saved_reels', 'reel_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Utilisateurs ayant marqué ce Reel comme "Ça ne m'intéresse pas".
     */
    public function notInterestedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_not_interested_reels', 'reel_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Vérifier si un utilisateur a aimé ce Reel.
     */
    public function isLikedBy(User $user): bool
    {
        return $this->reactions()
            ->where('user_id', $user->id)
            ->where('type', ReelReactionType::Like->value)
            ->exists();
    }

    /**
     * Vérifier si un utilisateur a disliké ce Reel.
     */
    public function isDislikedBy(User $user): bool
    {
        return $this->reactions()
            ->where('user_id', $user->id)
            ->where('type', ReelReactionType::Dislike->value)
            ->exists();
    }

    /**
     * Vérifier si un utilisateur a sauvegardé ce Reel.
     */
    public function isSavedBy(User $user): bool
    {
        return $this->savedByUsers()->where('users.id', $user->id)->exists();
    }
}
