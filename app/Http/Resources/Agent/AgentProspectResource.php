<?php

namespace App\Http\Resources\Agent;

use App\Models\Visit\VisitReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VisitReservation
 */
class AgentProspectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'listing_title' => $this->listing->title ?? null,
            'status' => $this->status->value,
            'prospect' => [
                'id' => $this->prospect_id,
                'name' => $this->prospect->name ?? $this->prospect->display_name ?? null,
                'handle' => $this->prospect->handle ?? null,
                'avatar_url' => $this->prospect->avatar_url ?? null,
                'phone' => $this->status->grantsPrivateAddressAccess() ? ($this->metadata['prospect_phone'] ?? $this->prospect->phone ?? null) : null,
            ],
            'slot' => $this->slot ? [
                'id' => $this->slot->id,
                'start_at' => $this->slot->start_at->toIso8601String(),
                'end_at' => $this->slot->end_at->toIso8601String(),
            ] : null,
            'fee' => (float) $this->fee_amount,
            'currency' => $this->currency ?? 'XOF',
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
