<?php

namespace App\Exceptions\Boost;

use Exception;
use Illuminate\Http\JsonResponse;

class UnauthorizedContentPromotionException extends Exception
{
    public function __construct(string $message = 'Vous ne pouvez promouvoir que les contenus dont vous êtes l\'auteur ou le gestionnaire.')
    {
        parent::__construct($message, 403);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'UNAUTHORIZED_CONTENT_PROMOTION',
        ], 403);
    }
}
