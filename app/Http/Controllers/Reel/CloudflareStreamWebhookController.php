<?php

namespace App\Http\Controllers\Reel;

use App\Enums\Reel\ReelStatus;
use App\Http\Controllers\Controller;
use App\Models\Reel\Reel;
use App\Services\Reel\CloudflareStreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CloudflareStreamWebhookController extends Controller
{
    public function __construct(protected CloudflareStreamService $cloudflareStream) {}

    /**
     * Traite les notifications webhook émises par Cloudflare Stream de façon sécurisée et idempotente.
     */
    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header('Webhook-Signature');
        $payload = $request->getContent();
        $secret = config('services.cloudflare_stream.webhook_secret');

        // Vérification obligatoire de la signature si le secret est configuré
        if ($secret) {
            if (! $signature || ! $this->cloudflareStream->verifyWebhookSignature($signature, $payload)) {
                Log::warning('Signature webhook Cloudflare Stream invalide ou absente');

                return response()->json(['error' => 'Invalid or expired signature'], 401);
            }
        } elseif (app()->environment('production')) {
            Log::critical('Tentative de webhook Cloudflare Stream sans secret configuré en production');

            return response()->json(['error' => 'Webhook secret required in production'], 403);
        }

        $data = $request->json()->all();
        $uid = $data['uid'] ?? null;
        $state = $data['status']['state'] ?? null;

        if (! $uid) {
            return response()->json(['error' => 'Missing video UID'], 400);
        }

        $reel = Reel::where('stream_media_id', $uid)->first();
        if (! $reel) {
            Log::info("Reel avec stream_media_id={$uid} introuvable pour ce webhook.");

            return response()->json(['status' => 'ignored'], 200);
        }

        // Sécurité : un webhook ne peut pas marquer arbitrairement un brouillon non publié comme Ready
        if ($reel->status === ReelStatus::Draft) {
            Log::warning("Webhook ignoré : le Reel {$reel->id} est encore à l'état de brouillon.");

            return response()->json(['error' => 'Reel is currently in draft state'], 400);
        }

        if ($state === 'ready') {
            // Idempotence : si déjà ready, ne pas re-modifier
            if ($reel->status === ReelStatus::Ready) {
                return response()->json(['status' => 'already_ready'], 200);
            }

            $reel->update([
                'status' => ReelStatus::Ready,
                'published_at' => $reel->published_at ?? now(),
                'error_message' => null,
            ]);
            Log::info("Reel {$reel->id} marqué 'ready' via webhook Cloudflare Stream.");
        } elseif ($state === 'error') {
            $reel->update([
                'status' => ReelStatus::Failed,
                'error_message' => $data['status']['errorReasonText'] ?? 'Erreur de transcodage Cloudflare Stream.',
            ]);
            Log::error("Reel {$reel->id} marqué 'failed' via webhook Cloudflare Stream: {$reel->error_message}");
        }

        return response()->json(['status' => 'processed'], 200);
    }
}
