<?php

namespace App\Http\Resources\Reel;

use App\Models\Reel\Series;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Series
 */
class SeriesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creator = $this->creator;
        $page = $this->page;

        return [
            'id' => (int) $this->id,
            'name' => $this->title,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'cover_url' => $this->cover_url,
            'coverUrl' => $this->cover_url,
            'episodes_count' => (int) ($this->episodes_count ?: $this->reels()->count()),
            'episodesCount' => (int) ($this->episodes_count ?: $this->reels()->count()),
            'is_active' => (bool) $this->is_active,
            'creator' => $creator ? [
                'id' => (int) $creator->id,
                'name' => $creator->display_name ?: $creator->name,
                'handle' => '@'.($creator->handle ?? 'user'),
                'avatar_url' => $creator->avatar_url,
                'is_certified' => (bool) $creator->is_certified,
            ] : null,
            'page' => $page ? [
                'id' => (int) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'is_certified' => $page->isCertified(),
            ] : null,
            'episodes' => ReelResource::collection($this->whenLoaded('reels', $this->reels, fn () => $this->reels)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
