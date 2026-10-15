<?php

namespace App\Services\Reel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CloudflareStreamService
{
    protected ?string $accountId;

    protected ?string $apiToken;

    protected ?string $customerSubdomain;

    protected ?string $webhookSecret;

    public function __construct()
    {
        $this->accountId = config('services.cloudflare_stream.account_id');
        $this->apiToken = config('services.cloudflare_stream.api_token');
        $this->customerSubdomain = config('services.cloudflare_stream.customer_subdomain');
        $this->webhookSecret = config('services.cloudflare_stream.webhook_secret');
    }

    /**
     * Vérifie si le service Cloudflare Stream est configuré avec ses clés d'API.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->accountId) && ! empty($this->apiToken);
    }

    /**
     * Crée une URL d'envoi direct (Direct Creator Upload) pour Cloudflare Stream.
     *
     * @return array{upload_url: string, uid: string}|null
     */
    public function createDirectUploadUrl(int $maxDurationSeconds = 180): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->post("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/stream/direct_upload", [
                    'maxDurationSeconds' => $maxDurationSeconds,
                    'requireSignedURLs' => false,
                ]);

            if ($response->successful()) {
                $data = $response->json('result');

                return [
                    'upload_url' => $data['uploadURL'] ?? '',
                    'uid' => $data['uid'] ?? '',
                ];
            }

            Log::error('Erreur Cloudflare Stream direct_upload', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Exception lors de la création d\'upload Cloudflare Stream', [
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Récupère les métadonnées et l'état de transcodage d'une vidéo sur Cloudflare Stream.
     *
     * @return array{
     *     status: string,
     *     ready_to_stream: bool,
     *     duration: float,
     *     hls_url: string,
     *     thumbnail_url: string,
     *     preview_url: string
     * }|null
     */
    public function getVideoDetails(string $uid): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->get("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/stream/{$uid}");

            if ($response->successful()) {
                $result = $response->json('result');
                $subdomain = $this->customerSubdomain ?: 'cloudflarestream.com';

                return [
                    'status' => $result['status']['state'] ?? 'unknown',
                    'ready_to_stream' => $result['readyToStream'] ?? false,
                    'duration' => (float) ($result['duration'] ?? 0),
                    'hls_url' => $result['playback']['hls'] ?? "https://{$subdomain}/{$uid}/manifest/video.m3u8",
                    'thumbnail_url' => $result['thumbnail'] ?? "https://{$subdomain}/{$uid}/thumbnails/thumbnail.jpg",
                    'preview_url' => $result['preview'] ?? "https://{$subdomain}/{$uid}/watch",
                ];
            }
        } catch (\Throwable $e) {
            Log::error('Exception récupération détails vidéo Cloudflare Stream', [
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Supprime une vidéo de Cloudflare Stream.
     */
    public function deleteVideo(string $uid): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withToken($this->apiToken)
                ->delete("https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/stream/{$uid}");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Exception suppression vidéo Cloudflare Stream', [
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Vérifie la signature d'un webhook Cloudflare Stream.
     */
    public function verifyWebhookSignature(?string $signatureHeader, string $payload): bool
    {
        $secret = $this->webhookSecret ?: config('services.cloudflare_stream.webhook_secret');

        if (empty($secret) || empty($signatureHeader)) {
            return false;
        }

        // Cloudflare Stream webhook signature format: time=...,sig1=...
        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $split = explode('=', $part, 2);
            if (count($split) === 2) {
                $parts[trim($split[0])] = trim($split[1]);
            }
        }

        if (! isset($parts['time'], $parts['sig1'])) {
            return false;
        }

        $timestamp = (int) $parts['time'];

        // Protection contre les attaques par rejeu : tolérance maximale de 300 secondes (5 minutes)
        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$parts['time']}.{$payload}", $secret);

        return hash_equals($expected, $parts['sig1']);
    }
}
