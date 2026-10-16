<?php

namespace App\Exceptions\Content;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceLockedException extends Exception
{
    public function __construct(
        string $message = 'Cette ressource est temporairement verrouillée ou indisponible suite à une décision de modération.',
        public readonly string $errorCode = 'RESOURCE_LOCKED',
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceId = null
    ) {
        parent::__construct($message, 423);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ], 423);
    }
}
