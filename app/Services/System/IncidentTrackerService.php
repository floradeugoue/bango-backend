<?php

namespace App\Services\System;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IncidentTrackerService
{
    /**
     * Clés de paramètres sensibles à masquer systématiquement dans les logs d'incidents.
     *
     * @var array<string>
     */
    protected const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'secret',
        'pin',
        'momo_pin',
        'service_secret',
        'api_key',
        'credit_card',
        'cvv',
        'kyc',
    ];

    /**
     * Génère un identifiant d'incident unique et traçable pour le support BANGO.
     * Format : ERR-BANGO-YYYYMMDD-XXXXXX
     */
    public function generateIncidentId(): string
    {
        $date = Carbon::now()->format('Ymd');
        $random = strtoupper(Str::random(6));

        return "ERR-BANGO-{$date}-{$random}";
    }

    /**
     * Journalise de manière structurée l'incident serveur avec masquage des données sensibles.
     *
     * @return array<string, mixed>
     */
    public function logIncident(Throwable $exception, Request $request, ?string $incidentId = null, int $statusCode = 500): array
    {
        $incidentId ??= $this->generateIncidentId();
        $user = $request->user();

        $context = [
            'incident_id' => $incidentId,
            'status_code' => $statusCode,
            'environment' => config('app.env', 'production'),
            'timestamp' => Carbon::now()->toIso8601String(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route' => $request->route()?->getName() ?? $request->path(),
            'client_ip' => $request->ip(),
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'sanitized_inputs' => $this->sanitizeData($request->except(['file', 'files', 'attachments'])),
            'sanitized_headers' => $this->sanitizeHeaders($request->headers->all()),
        ];

        Log::error("[INCIDENT:{$incidentId}] Erreur serveur inattendue : {$exception->getMessage()}", $context);

        return $context;
    }

    /**
     * Génère la réponse JSON sécurisée standardisée pour l'API client.
     */
    public function renderIncidentResponse(Throwable $exception, Request $request, ?string $incidentId = null, int $statusCode = 500): JsonResponse
    {
        $incidentId ??= $this->generateIncidentId();
        $this->logIncident($exception, $request, $incidentId, $statusCode);

        $payload = [
            'message' => 'Une erreur serveur inattendue est survenue.',
            'code' => 'INTERNAL_SERVER_ERROR',
            'incident_id' => $incidentId,
        ];

        // En mode debug uniquement, enrichir la réponse avec des détails techniques
        if (config('app.debug')) {
            $payload['debug'] = [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        return response()->json($payload, $statusCode, [
            'X-Incident-Id' => $incidentId,
        ]);
    }

    /**
     * Masque récursivement les valeurs des clés sensibles.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $sanitized[$key] = '******** [MASQUÉ]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Masque les en-têtes HTTP sensibles.
     *
     * @param  array<string, array<string>>  $headers
     * @return array<string, mixed>
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];

        foreach ($headers as $name => $values) {
            $normalized = strtolower((string) $name);
            if ($this->isSensitiveKey($normalized)) {
                $sanitized[$name] = ['******** [MASQUÉ]'];
            } else {
                $sanitized[$name] = $values;
            }
        }

        return $sanitized;
    }

    /**
     * Détermine si une clé correspond à une donnée confidentielle.
     */
    protected function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
