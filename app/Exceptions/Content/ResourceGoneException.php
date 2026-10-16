<?php

namespace App\Exceptions\Content;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceGoneException extends Exception
{
    public function __construct(
        string $message = 'Cette ressource a été supprimée définitivement par son auteur.',
        public readonly string $errorCode = 'RESOURCE_GONE',
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceId = null
    ) {
        parent::__construct($message, 410);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ], 410);
    }
}
