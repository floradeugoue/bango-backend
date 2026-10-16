<?php

namespace App\Http\Resources\Payment;

use App\Models\Payment\SavedPaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedPaymentMethod
 */
class SavedPaymentMethodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'operator' => $this->operator,
            'phone_number' => $this->masked_phone_number,
            'raw_phone_number' => $this->phone_number,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
