<?php

namespace App\Exceptions\Boost;

use App\Enums\Boost\CampaignStatus;
use Exception;
use Illuminate\Http\JsonResponse;

class InvalidCampaignTransitionException extends Exception
{
    public function __construct(
        public readonly CampaignStatus $fromStatus,
        public readonly CampaignStatus $toStatus,
        string $message = 'Transition de statut de campagne non autorisée.'
    ) {
        parent::__construct(
            message: sprintf('%s (%s -> %s)', $message, $fromStatus->value, $toStatus->value),
            code: 422
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'INVALID_CAMPAIGN_TRANSITION',
            'from_status' => $this->fromStatus->value,
            'to_status' => $this->toStatus->value,
        ], 422);
    }
}
