<?php

namespace App\Exceptions\Upload;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TusUploadException extends Exception
{
    public function __construct(
        string $message,
        int $statusCode = 400,
        public readonly string $errorCode = 'TUS_UPLOAD_ERROR'
    ) {
        parent::__construct($message, $statusCode);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => $this->errorCode,
        ], $this->getCode() ?: 400, [
            'Tus-Resumable' => '1.0.0',
        ]);
    }
}
