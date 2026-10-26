<?php

namespace App\Exceptions\Boost;

use Exception;
use Illuminate\Http\JsonResponse;

class InsufficientCreditException extends Exception
{
    public function __construct(
        public readonly int|float $currentBalance,
        public readonly int|float $requiredAmount,
        string $message = 'Solde de Crédit BANGO insuffisant pour financer cette opération.'
    ) {
        parent::__construct($message, 422);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'INSUFFICIENT_CREDIT',
            'kind' => 'insufficient_credit',
            'current_balance' => $this->currentBalance,
            'required_amount' => $this->requiredAmount,
        ], 422);
    }
}
