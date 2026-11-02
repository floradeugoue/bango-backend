<?php

namespace App\Http\Resources\Landlord;

use App\Models\Landlord\LandlordSlotRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LandlordSlotRule
 */
class LandlordSlotRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'days' => $this->days ?? [],
            'minimum_hours' => (int) ($this->minimum_hours ?? 4),
            'grouped' => (bool) ($this->grouped ?? false),
            'group_size' => (int) ($this->group_size ?? 5),
            'auto_confirm' => (bool) ($this->auto_confirm ?? false),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
