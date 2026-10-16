<?php

namespace App\Http\Resources\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Page\Page;
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

        $page = $this->page_id ? ($this->relationLoaded('page') ? $this->page : Page::find($this->page_id)) : null;

        $reelAuthorName = $page ? $page->name : ($author?->name ?? 'Anonyme');
        $reelAuthorVerified = $page ? $page->isCertified() : (bool) ($author?->is_verified ?? false);
        $reelAuthorBadge = $page ? ($page->isCertified() ? 'page_certified' : 'none') : $author?->badge;
        $reelHandle = '@'.($page ? $page->handle : ($author?->handle ?? 'user'));

        return [
            'id' => (string) $this->id,
            'authorId' => (string) $this->user_id,
            'author_id' => $this->user_id,
            'page_id' => $this->page_id ? (int) $this->page_id : null,
            'is_page_reel' => $page !== null,
            'page' => $page ? [
                'id' => (int) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'is_certified' => $page->isCertified(),
            ] : null,
            'authorName' => $reelAuthorName,
            'author_name' => $reelAuthorName,
            'authorVerified' => $reelAuthorVerified,
            'author_verified' => $reelAuthorVerified,
            'authorBadge' => $reelAuthorBadge,
            'author_badge' => $reelAuthorBadge,
            'authorMeta' => $authorMeta,
            'author_meta' => $authorMeta,
            'is_sponsored' => (bool) ($this->is_sponsored ?? false),
            'campaign_id' => $this->campaign_id ?? null,
            'sponsored_by' => $this->sponsored_by ?? null,
            'sponsor_branding' => $this->sponsor_branding ?? null,
            'caption' => $this->caption ?? '',
            'handle' => $reelHandle,
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
