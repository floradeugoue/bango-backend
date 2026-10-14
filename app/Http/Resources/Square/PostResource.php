<?php

namespace App\Http\Resources\Square;

use App\Enums\Square\PostMediaKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mediaCollection = $this->relationLoaded('media') ? $this->media : collect();
        $firstMedia = $mediaCollection->first();

        // Calcul des photos pour compatibilité carrousel
        $photoUrls = $mediaCollection
            ->filter(fn ($m) => ($m->media_type === PostMediaKind::Photo || $m->media_type === 'photo'))
            ->pluck('media_url')
            ->values()
            ->all();

        // Aspect ratio calculé
        $aspect = null;
        if ($firstMedia && $firstMedia->width && $firstMedia->height && $firstMedia->height > 0) {
            $aspect = round($firstMedia->width / $firstMedia->height, 3);
        }

        // Tags normalisés
        $allTags = [];
        foreach ($mediaCollection as $mediaItem) {
            if ($mediaItem->relationLoaded('tags')) {
                foreach ($mediaItem->tags as $tag) {
                    $allTags[] = [
                        'accountId' => (string) $tag->user_id,
                        'account_id' => (string) $tag->user_id,
                        'handle' => $tag->user?->handle ?? '',
                        'name' => $tag->user?->display_name ?? $tag->user?->name ?? '',
                        'x' => (float) $tag->x,
                        'y' => (float) $tag->y,
                        'mediaIndex' => (int) $mediaItem->display_order,
                    ];
                }
            }
        }

        $currentUser = $request->user();
        $isMine = $currentUser ? ((int) $currentUser->id === (int) $this->user_id) : false;

        $audienceVal = is_object($this->audience) ? $this->audience->value : $this->audience;
        $commentsByVal = is_object($this->comments_by) ? $this->comments_by->value : $this->comments_by;

        return [
            'id' => (string) $this->id,
            'authorId' => (string) $this->user_id,
            'author_id' => (string) $this->user_id,
            'authorName' => $this->user?->display_name ?? $this->user?->name ?? 'Utilisateur',
            'author_name' => $this->user?->display_name ?? $this->user?->name ?? 'Utilisateur',
            'authorAvatar' => $this->user?->avatar_url,
            'author_avatar' => $this->user?->avatar_url,
            'authorVerified' => (bool) ($this->user?->is_verified ?? false),
            'author_verified' => (bool) ($this->user?->is_verified ?? false),
            'authorBadge' => $this->user?->badge ?? 'none',
            'author_badge' => $this->user?->badge ?? 'none',
            'authorMeta' => $this->user?->handle ? '@'.$this->user->handle : null,

            // Contenu
            'body' => (string) ($this->body ?? ''),
            'caption' => (string) ($this->body ?? ''),

            // Médias
            'media' => $firstMedia?->media_url,
            'mediaType' => $firstMedia ? (is_object($firstMedia->media_type) ? $firstMedia->media_type->value : $firstMedia->media_type) : null,
            'photo' => ! empty($photoUrls) ? $photoUrls[0] : null,
            'photos' => $photoUrls,
            'aspect' => $aspect,
            'media_items' => PostMediaResource::collection($mediaCollection),

            // Lieu
            'place' => $this->place_label,
            'place_details' => [
                'label' => $this->place_label,
                'detail' => $this->place_detail,
                'city' => $this->place_city,
                'neighbourhood' => $this->place_neighbourhood,
            ],

            // Compteurs & Statuts
            'likes' => (int) $this->likes_count,
            'likes_count' => (int) $this->likes_count,
            'comments' => (int) $this->comments_count,
            'comments_count' => (int) $this->comments_count,
            'shares' => (int) $this->shares_count,
            'shares_count' => (int) $this->shares_count,
            'liked' => false, // Initialisé à false, personnalisable avec table des likes future

            // Paramètres de publication
            'audience' => $audienceVal,
            'commentsBy' => $commentsByVal,
            'comments_by' => $commentsByVal,
            'commentsClosed' => (bool) $this->comments_closed,
            'comments_closed' => (bool) $this->comments_closed,
            'shareable' => (bool) $this->allow_share,
            'allow_share' => (bool) $this->allow_share,
            'hideLikes' => (bool) $this->hide_likes,
            'hide_likes' => (bool) $this->hide_likes,

            // Flags d'état
            'mine' => $isMine,
            'edited' => (bool) $this->is_edited,
            'is_edited' => (bool) $this->is_edited,
            'is_archived' => (bool) $this->is_archived,

            // Horodatage
            'at' => $this->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Relations
            'tags' => $allTags,
            'tagged' => collect($allTags)->pluck('handle')->filter()->values()->all(),
            'hashtags' => $this->relationLoaded('hashtags') ? $this->hashtags->pluck('name')->all() : [],
        ];
    }
}
