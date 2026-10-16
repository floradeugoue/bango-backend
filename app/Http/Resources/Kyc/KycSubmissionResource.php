<?php

namespace App\Http\Resources\Kyc;

use App\Models\Kyc\KycSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KycSubmission
 */
class KycSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'full_name' => $this->full_name,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'expires_on' => $this->expires_on?->format('Y-m-d'),
            'property_right' => $this->property_right,
            'deed_type' => $this->deed_type,
            'rejection_reason' => $this->rejection_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'documents' => KycDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
