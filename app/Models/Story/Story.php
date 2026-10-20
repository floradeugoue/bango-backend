<?php

namespace App\Models\Story;

use App\Enums\Story\StoryAudience;
use App\Enums\Story\StoryStatus;
use App\Models\User;
use Database\Factories\Story\StoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Story extends Model
{
    /** @use HasFactory<StoryFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'audience',
        'allow_replies',
        'allow_archive',
        'status',
        'published_at',
        'expires_at',
        'views_count',
        'poll_votes_count',
        'replies_count',
        'likes_count',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => StoryAudience::class,
            'status' => StoryStatus::class,
            'allow_replies' => 'boolean',
            'allow_archive' => 'boolean',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'views_count' => 'integer',
            'poll_votes_count' => 'integer',
            'replies_count' => 'integer',
            'likes_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(StoryLike::class);
    }

    public function slides(): HasMany
    {
        return $this->hasMany(StorySlide::class)->orderBy('order', 'asc');
    }

    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class);
    }

    public function viewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'story_views', 'story_id', 'viewer_id')
            ->withPivot('viewed_at', 'ip_address')
            ->withTimestamps();
    }

    public function replies(): HasMany
    {
        return $this->hasMany(StoryReply::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(StoryReport::class);
    }

    public function highlights(): BelongsToMany
    {
        return $this->belongsToMany(StoryHighlight::class, 'story_highlight_items', 'story_id', 'highlight_id')
            ->withPivot('order')
            ->withTimestamps();
    }

    /**
     * Scope pour les Stories actives (non expirées, publiées, statut active).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StoryStatus::Active->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    /**
     * Scope pour les Stories expirées.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('expires_at', '<=', now())
                ->orWhere('status', StoryStatus::Expired->value);
        });
    }

    /**
     * Scope pour les Stories archivées.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', StoryStatus::Archived->value)
                ->orWhere(function (Builder $sub) {
                    $sub->where('status', StoryStatus::Active->value)
                        ->where('allow_archive', true)
                        ->where('expires_at', '<=', now());
                });
        });
    }

    /**
     * Scope pour filtrer les Stories selon la visibilité pour un utilisateur donné.
     */
    public function scopeAccessibleBy(Builder $query, User $viewer): Builder
    {
        return $query->where(function (Builder $builder) use ($viewer) {
            // L'auteur peut toujours voir ses propres stories
            $builder->where('user_id', $viewer->id)
                ->orWhere(function (Builder $sub) use ($viewer) {
                    // Exclure les auteurs bloqués ou bloquants
                    $sub->whereNotIn('user_id', function ($q) use ($viewer) {
                        $q->select('blocked_id')->from('user_blocks')->where('blocker_id', $viewer->id);
                    })->whereNotIn('user_id', function ($q) use ($viewer) {
                        $q->select('blocker_id')->from('user_blocks')->where('blocked_id', $viewer->id);
                    });

                    // Règles d'audience
                    $sub->where(function (Builder $aud) use ($viewer) {
                        // 1. Audience tout le monde
                        $aud->where('audience', StoryAudience::Everyone->value)
                            // 2. Audience followers (l'observateur doit suivre l'auteur)
                            ->orWhere(function (Builder $f) use ($viewer) {
                                $f->where('audience', StoryAudience::Followers->value)
                                    ->whereIn('user_id', function ($q) use ($viewer) {
                                        $q->select('following_id')->from('follows')->where('follower_id', $viewer->id);
                                    });
                            })
                            // 3. Audience liste choisie (l'observateur doit être dans la liste choisie de l'auteur)
                            ->orWhere(function (Builder $c) use ($viewer) {
                                $c->where('audience', StoryAudience::Chosen->value)
                                    ->whereIn('user_id', function ($q) use ($viewer) {
                                        $q->select('user_id')->from('user_close_friends')->where('friend_id', $viewer->id);
                                    });
                            });
                    });
                });
        });
    }

    /**
     * Vérifie si la Story est actuellement expirée.
     */
    public function isExpired(): bool
    {
        if ($this->status === StoryStatus::Expired) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Vérifie si un utilisateur a le droit de consulter cette Story.
     */
    public function isAccessibleBy(User $viewer): bool
    {
        if ($this->user_id === $viewer->id) {
            return true;
        }

        // Vérification des blocages
        if ($viewer->hasBlocked($this->user_id) || $viewer->isBlockedBy($this->user_id)) {
            return false;
        }

        // Audience Everyone
        if ($this->audience === StoryAudience::Everyone) {
            return true;
        }

        // Audience Followers
        if ($this->audience === StoryAudience::Followers) {
            return $viewer->isFollowing($this->user_id);
        }

        // Audience Chosen (Liste Choisie)
        if ($this->audience === StoryAudience::Chosen) {
            return $this->user->closeFriends()->where('friend_id', $viewer->id)->exists();
        }

        return false;
    }

    /**
     * Vérifie si un utilisateur a déjà vu cette Story.
     */
    public function hasViewedBy(User $viewer): bool
    {
        return $this->views()->where('viewer_id', $viewer->id)->exists();
    }

    /**
     * Vérifie si un utilisateur peut répondre en privé à cette Story.
     */
    public function canReply(User $viewer): bool
    {
        if (! $this->allow_replies) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        if ($this->user_id === $viewer->id) {
            return false; // On ne s'auto-répond pas en privé
        }

        return $this->isAccessibleBy($viewer);
    }
}
