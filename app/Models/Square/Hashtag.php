<?php

namespace App\Models\Square;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Hashtag extends Model
{
    use HasFactory;

    protected $table = 'hashtags';

    protected $fillable = [
        'name',
        'normalized_name',
        'posts_count',
        'followers_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posts_count' => 'integer',
            'followers_count' => 'integer',
        ];
    }

    /**
     * Publications utilisant ce hashtag.
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_hashtag')->withTimestamps();
    }

    /**
     * Utilisateurs abonnés à ce hashtag.
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'hashtag_followers')->withTimestamps();
    }

    /**
     * Vérifie si un utilisateur est abonné à ce hashtag.
     */
    public function isFollowedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->followers()->where('users.id', $user->id)->exists();
    }

    /**
     * Scope pour filtrer les hashtags ayant au moins une publication visible.
     */
    public function scopeWithVisiblePosts(Builder $query): Builder
    {
        return $query->where('posts_count', '>', 0)
            ->where(function (Builder $q) {
                $q->whereHas('posts', function (Builder $postQuery) {
                    $postQuery->whereNull('posts.deleted_at')
                        ->where('posts.is_archived', false);
                })
                    ->orWhere(function (Builder $fallbackQ) {
                        $fallbackQ->where('posts_count', '>', 0)
                            ->whereDoesntHave('posts');
                    });
            });
    }

    /**
     * Recalcule le posts_count réel d'un hashtag à partir des publications visibles non supprimées.
     */
    public function recalculatePostsCount(): int
    {
        $count = $this->posts()
            ->whereNull('posts.deleted_at')
            ->where('posts.is_archived', false)
            ->count();

        $this->update(['posts_count' => $count]);

        return $count;
    }

    /**
     * Normalise une chaîne de hashtag pour recherche insensible à la casse.
     */
    public static function normalize(string $tag): string
    {
        $cleaned = ltrim(trim($tag), '#');

        return mb_strtolower($cleaned, 'UTF-8');
    }
}
