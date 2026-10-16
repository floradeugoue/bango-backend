<?php

namespace App\Http\Resources\Support;

use App\Models\Support\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'target_type' => Report::resolveShortType($this->reportable_type),
            'target_id' => (string) $this->reportable_id,
            'reason' => [
                'value' => $this->reason->value,
                'label' => $this->reason->label(),
            ],
            'details' => $this->details,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'action_taken' => $this->action_taken,
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
