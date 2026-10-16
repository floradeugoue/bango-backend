<?php

namespace App\Exceptions\Upload;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TusOffsetConflictException extends Exception
{
    public function __construct(
        public readonly int $currentOffset,
        public readonly int $expectedOffset,
        string $message = 'Conflit d\'offset Tus : l\'offset transmis ne correspond pas à l\'état actuel du serveur.'
    ) {
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error' => 'TUS_OFFSET_CONFLICT',
            'current_offset' => $this->currentOffset,
            'expected_offset' => $this->expectedOffset,
        ], 409, [
            'Tus-Resumable' => '1.0.0',
            'Upload-Offset' => (string) $this->currentOffset,
        ]);
    }
}
