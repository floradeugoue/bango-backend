<?php

namespace App\Http\Resources\Boost;

use App\Models\Boost\CampaignMetric;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CampaignMetric
 */
class CampaignMetricResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'metric_date' => $this->metric_date?->toDateString(),
            'impressions' => $this->impressions,
            'clicks' => $this->clicks,
            'reach' => $this->reach,
            'spent' => (float) $this->spent,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
