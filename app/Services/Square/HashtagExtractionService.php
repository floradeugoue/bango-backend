<?php

namespace App\Services\Square;

use App\Models\Square\Hashtag;
use App\Models\Square\Post;

class HashtagExtractionService
{
    /**
     * Regex Unicode pour extraire les hashtags (#mot_123).
     */
    protected const HASHTAG_REGEX = '/#([\p{L}\p{N}_]+)/u';

    /**
     * Extrait les hashtags uniques d'un texte.
     *
     * @return array<string>
     */
    public function extract(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        preg_match_all(self::HASHTAG_REGEX, $text, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $unique = [];
        foreach ($matches[1] as $rawTag) {
            $normalized = Hashtag::normalize($rawTag);
            if (! isset($unique[$normalized])) {
                $unique[$normalized] = $rawTag;
            }
        }

        return array_values($unique);
    }

    /**
     * Synchronise les hashtags d'un post (ajoute les nouveaux, met à jour les compteurs).
     */
    public function syncPostHashtags(Post $post, ?string $text): void
    {
        $tags = $text ? $this->extract($text) : [];

        // Récupérer les hashtags actuellement associés
        $currentHashtagIds = $post->hashtags()->pluck('hashtags.id')->all();

        $newHashtagIds = [];
        foreach ($tags as $rawTag) {
            $normalized = Hashtag::normalize($rawTag);

            $hashtag = Hashtag::firstOrCreate(
                ['normalized_name' => $normalized],
                ['name' => $rawTag, 'posts_count' => 0]
            );

            $newHashtagIds[] = $hashtag->id;
        }

        // Trouver les IDs retirés et ajoutés
        $toDetach = array_diff($currentHashtagIds, $newHashtagIds);
        $toAttach = array_diff($newHashtagIds, $currentHashtagIds);

        if (! empty($toDetach)) {
            $post->hashtags()->detach($toDetach);
            Hashtag::whereIn('id', $toDetach)->where('posts_count', '>', 0)->decrement('posts_count');
        }

        if (! empty($toAttach)) {
            $post->hashtags()->attach($toAttach);
            Hashtag::whereIn('id', $toAttach)->increment('posts_count');
        }
    }

    /**
     * Détache tous les hashtags lors de la suppression d'un post et décrémente leurs compteurs.
     */
    public function detachAll(Post $post): void
    {
        $hashtagIds = $post->hashtags()->pluck('hashtags.id')->all();

        if (! empty($hashtagIds)) {
            $post->hashtags()->detach($hashtagIds);
            Hashtag::whereIn('id', $hashtagIds)->where('posts_count', '>', 0)->decrement('posts_count');
        }
    }
}
