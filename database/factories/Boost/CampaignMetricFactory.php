<?php

namespace Database\Factories\Boost;

use App\Models\Boost\Campaign;
use App\Models\Boost\CampaignMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignMetric>
 */
class CampaignMetricFactory extends Factory
{
    protected $model = CampaignMetric::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'metric_date' => now()->toDateString(),
            'impressions' => 1500,
            'clicks' => 85,
            'reach' => 1200,
            'spent' => '714.28',
            'metadata' => null,
        ];
    }
}
