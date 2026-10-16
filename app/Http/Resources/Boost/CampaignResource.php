<?php

namespace App\Http\Resources\Boost;

use App\Models\Boost\Campaign;
use App\Models\Listing\Listing;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $shortKind = match ($this->boostable_type) {
            Post::class, 'post', 'posts' => 'post',
            Reel::class, 'reel', 'reels' => 'reel',
            Listing::class, 'listing', 'listings' => 'listing',
            default => 'unknown',
        };

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'page_id' => $this->page_id ? (int) $this->page_id : null,
            'page' => $this->page ? [
                'id' => (int) $this->page->id,
                'name' => $this->page->name,
                'handle' => $this->page->handle,
                'logo_url' => $this->page->logo_url,
                'is_certified' => $this->page->isCertified(),
            ] : null,
            'sponsor_type' => $this->page_id ? 'page' : 'user',
            'sponsored_by' => $this->page ? "Sponsorisé par {$this->page->name}" : ($this->metadata['sponsored_by'] ?? ($this->user?->name ?? 'Sponsorisé')),
            'kind' => $shortKind,
            'boostable_type' => $this->boostable_type,
            'boostable_id' => $this->boostable_id,
            'content_label' => $this->metadata['content_label'] ?? null,
            'objective' => [
                'value' => $this->objective->value,
                'label' => $this->objective->label(),
            ],
            'budget' => (float) $this->budget_amount,
            'currency' => $this->budget_currency,
            'duration_days' => $this->duration_days,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'targeting' => $this->targeting,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'stopped_reason' => $this->stopped_reason,
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'spent' => (float) $this->spent_amount,
            'unspent' => max(0.0, (float) $this->budget_amount - (float) $this->spent_amount),
            'impressions_count' => $this->impressions_count,
            'clicks_count' => $this->clicks_count,
            'reach_count' => $this->reach_count,
            'metrics' => CampaignMetricResource::collection($this->whenLoaded('metrics')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
