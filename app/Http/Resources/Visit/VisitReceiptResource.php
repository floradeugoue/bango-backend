<?php

namespace App\Http\Resources\Visit;

use App\Models\Visit\VisitReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VisitReservation
 */
class VisitReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'receipt_id' => 'REC-VISIT-'.$this->id,
            'reservation_id' => $this->id,
            'payment_reference' => $this->payment_reference,
            'short_reference' => $this->short_reference,
            'prospect' => $this->prospect ? [
                'name' => $this->prospect?->display_name ?: $this->prospect?->name,
                'handle' => $this->prospect?->handle,
                'email' => $this->prospect?->email,
                'phone' => $this->prospect_phone ?? $this->prospect?->phone,
            ] : null,
            'listing' => [
                'id' => $this->listing->id,
                'title' => $this->listing->title,
                'city' => $this->listing->city,
                'neighborhood' => $this->listing->neighborhood,
                'address' => $this->grantsPrivateAddressAccess() ? $this->listing->address : null,
                'lat' => $this->grantsPrivateAddressAccess() ? ($this->listing->lat !== null ? (float) $this->listing->lat : null) : null,
                'lng' => $this->grantsPrivateAddressAccess() ? ($this->listing->lng !== null ? (float) $this->listing->lng : null) : null,
            ],
            'visit_scheduled_at' => $this->slot?->start_at->toIso8601String(),
            'fee_amount' => (float) $this->fee_amount,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->status->value,
            'payment_channel' => $this->paymentTransaction?->channel?->value ?? 'mobile_money',
            'issued_at' => now()->toIso8601String(),
        ];
    }
}
