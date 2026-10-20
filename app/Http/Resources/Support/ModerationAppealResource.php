<?php

namespace App\Http\Resources\Support;

use App\Models\Support\ModerationAppeal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModerationAppeal
 */
class ModerationAppealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'reference' => $this->reference,
            'moderation_decision_id' => $this->moderation_decision_id,
            'sanction_type' => $this->sanction_type,
            'sanction_reference' => $this->sanction_reference,
            'reason' => $this->reason,
            'explanation' => $this->explanation,
            'contact_email' => $this->contact_email,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'decision_notes' => $this->decision_notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
