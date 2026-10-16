<?php

namespace App\Http\Resources\Credits;

use App\Models\Boost\CreditWallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditWallet
 */
class CreditWalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'balance' => (int) $this->balance,
            'currency' => $this->currency,
            'conversion_rate' => '1 FCFA = 1 Crédit BANGO',
            'recent_transactions' => CreditTransactionResource::collection($this->whenLoaded('transactions')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
