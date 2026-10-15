<?php

namespace App\Http\Resources\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Reel\Reel;
use App\Models\User;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reel
 */
class ReelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $author = $this->user;

        $isMine = $user ? $this->user_id === $user->id : false;
        $isFollowing = false;
        $isSaved = false;
        $userReaction = null;

        if ($user) {
            // Optimisation : si la relation reactions a été préchargée pour l'utilisateur
            if ($this->relationLoaded('reactions')) {
                $reaction = $this->reactions->firstWhere('user_id', $user->id);
                $userReaction = $reaction?->type instanceof ReelReactionType
                    ? $reaction->type->value
                    : ($reaction?->type ?? null);
            } else {
                $reaction = $this->reactions()->where('user_id', $user->id)->first();
                $userReaction = $reaction?->type instanceof ReelReactionType
                    ? $reaction->type->value
                    : ($reaction?->type ?? null);
            }

            if ($this->relationLoaded('savedByUsers')) {
                $isSaved = $this->savedByUsers->contains('id', $user->id);
            } else {
                $isSaved = $this->isSavedBy($user);
            }

            if ($author && $user->id !== $author->id) {
                $isFollowing = $user->isFollowing($author);
            }
        }

        $liked = $userReaction === ReelReactionType::Like->value;
        $disliked = $userReaction === ReelReactionType::Dislike->value;

        // Construction du libellé du son
        $soundLabel = 'Son original';
        $soundRemoved = false;
        if ($this->sound) {
            if ($this->sound->is_original) {
                $soundLabel = "Son original · {$author?->name}";
            } else {
                $soundLabel = "{$this->sound->artist} · {$this->sound->title}";
            }
            $soundRemoved = (bool) $this->sound->is_removed;
        } elseif ($this->sound_id && str_starts_with($this->sound_id, 'orig:')) {
            $soundLabel = "Son original · {$author?->name}";
        }

        // Formatage de la date de publication
        $publishedAtFormatted = $this->published_at
            ? $this->published_at->translatedFormat('d F Y')
            : ($this->created_at ? $this->created_at->translatedFormat('d F Y') : '');

        // Author meta (ex: "Almadies · il y a 2 h")
        $authorCity = $author?->city ?? 'Dakar';
        $timeAgo = $this->created_at?->diffForHumans() ?? '';
        $authorMeta = trim("{$authorCity} · {$timeAgo}", ' ·');

        /** @var MediaStorageService $storage */
        $storage = app(MediaStorageService::class);

        $videoUrl = $this->video_url ? $storage->getUrl($this->video_url) : null;
        $thumbnailUrl = $this->thumbnail_url ? $storage->getUrl($this->thumbnail_url) : null;
        $hlsUrl = $this->hls_url ? $storage->getUrl($this->hls_url) : $videoUrl;

        // Renditions par défaut si non fournies
        $renditions = $this->renditions ?? [];
        if (empty($renditions) && $videoUrl) {
            $renditions = [
                '480p' => $videoUrl,
                '720p' => $videoUrl,
                '1080p' => $videoUrl,
            ];
        } else {
            foreach ($renditions as $quality => $url) {
                $renditions[$quality] = $storage->getUrl($url);
            }
        }

        return [
            'id' => (string) $this->id,
            'authorId' => (string) $this->user_id,
            'author_id' => $this->user_id,
            'authorName' => $author?->name ?? 'Anonyme',
            'author_name' => $author?->name ?? 'Anonyme',
            'authorVerified' => (bool) ($author?->is_verified ?? false),
            'author_verified' => (bool) ($author?->is_verified ?? false),
            'authorBadge' => $author?->badge,
            'author_badge' => $author?->badge,
            'authorMeta' => $authorMeta,
            'author_meta' => $authorMeta,
            'caption' => $this->caption ?? '',
            'handle' => '@'.($author?->handle ?? 'user'),
            'sound' => $soundLabel,
            'soundId' => $this->sound_id ?? "orig:{$this->id}",
            'sound_id' => $this->sound_id ?? "orig:{$this->id}",
            'soundRemoved' => $soundRemoved,
            'sound_removed' => $soundRemoved,
            'publishedAt' => $publishedAtFormatted,
            'published_at' => $this->published_at?->toIso8601String(),
            'captions' => $this->subtitles,
            'subtitles' => $this->subtitles,
            'subtitles_locale' => $this->subtitles_locale,
            'downloadable' => (bool) $this->allow_download,
            'allow_download' => (bool) $this->allow_download,
            'likes' => (int) $this->likes_count,
            'likes_count' => (int) $this->likes_count,
            'dislikes' => (int) $this->dislikes_count,
            'dislikes_count' => (int) $this->dislikes_count,
            'comments' => (int) $this->comments_count,
            'comments_count' => (int) $this->comments_count,
            'shares' => (int) $this->shares_count,
            'shares_count' => (int) $this->shares_count,
            'views' => (int) $this->views_count,
            'views_count' => (int) $this->views_count,
            'tone' => 'deep',
            'photo' => $thumbnailUrl,
            'thumbnail_url' => $thumbnailUrl,
            'videoUrl' => $videoUrl,
            'video_url' => $videoUrl,
            'hlsUrl' => $hlsUrl,
            'hls_url' => $hlsUrl,
            'stream_media_id' => $this->stream_media_id,
            'renditions' => $renditions,
            'at' => $this->published_at?->getTimestampMs() ?? $this->created_at?->getTimestampMs(),
            'mine' => $isMine,
            'commentsClosed' => (bool) $this->comments_closed,
            'comments_closed' => (bool) $this->comments_closed,
            'topic' => $this->topic ?? 'neighbourhood',
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'error_message' => $this->error_message,
            'composition' => $this->composition,
            'listingId' => $this->listing_id,
            'listing_id' => $this->listing_id,
            'duration' => $this->duration,
            'width' => $this->width,
            'height' => $this->height,
            'aspect_ratio' => $this->aspect_ratio,
            // États utilisateur
            'liked' => $liked,
            'disliked' => $disliked,
            'user_reaction' => $userReaction,
            'saved' => $isSaved,
            'is_saved' => $isSaved,
            'following' => $isFollowing,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
