<?php

namespace App\Models\Square;

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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'posts_count' => 'integer',
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
     * Normalise une chaîne de hashtag pour recherche insensible à la casse.
     */
    public static function normalize(string $tag): string
    {
        $cleaned = ltrim(trim($tag), '#');

        return mb_strtolower($cleaned, 'UTF-8');
    }
}
