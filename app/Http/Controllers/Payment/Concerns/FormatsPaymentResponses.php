<?php

namespace App\Http\Controllers\Payment\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

trait FormatsPaymentResponses
{
    /**
     * Rendu d'une erreur de paiement normalisée et stable pour React Native.
     *
     * @param  array<string, mixed>  $details
     */
    protected function paymentError(
        string $code,
        string $message,
        int $status = 400,
        array $details = [],
        bool $retryable = false
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => empty($details) ? (object) [] : $details,
                'retryable' => $retryable,
            ],
            'meta' => [
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }

    /**
     * Rendu d'une réponse de succès structurée pour l'API mobile.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function paymentSuccess(
        mixed $data,
        ?string $message = null,
        int $status = 200,
        array $extra = []
    ): JsonResponse {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $payload['data'] = $data;

        if (! empty($extra)) {
            $payload = array_merge($payload, $extra);
        }

        return response()->json($payload, $status);
    }
}
