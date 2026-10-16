<?php

namespace App\Exceptions\Boost;

use Exception;
use Illuminate\Http\JsonResponse;

class InvalidBoostableContentException extends Exception
{
    public function __construct(string $message = 'Le contenu ciblé est introuvable, archivé, supprimé ou non éligible au boost.')
    {
        parent::__construct($message, 422);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'INVALID_BOOSTABLE_CONTENT',
        ], 422);
    }
}
