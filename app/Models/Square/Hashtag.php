<?php

namespace App\Models\Square;

use App\Models\User;
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
     * Normalise une chaîne de hashtag pour recherche insensible à la casse.
     */
    public static function normalize(string $tag): string
    {
        $cleaned = ltrim(trim($tag), '#');

        return mb_strtolower($cleaned, 'UTF-8');
    }
}
