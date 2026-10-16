<?php

namespace App\Exceptions\Content;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceNotFoundException extends Exception
{
    public function __construct(
        string $message = 'Ressource introuvable ou inaccessible.',
        public readonly string $errorCode = 'RESOURCE_NOT_FOUND',
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceId = null
    ) {
        parent::__construct($message, 404);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ], 404);
    }
}
