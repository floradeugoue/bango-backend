<?php

namespace App\Services\Sms;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZomloaSmsService
{
    /**
     * Send an OTP SMS via Zomloa API
     *
     * @return array|null
     *
     * @throws Exception
     */
    public function send(string $recipient, string $content, ?string $countryCode = null)
    {
        $apiKey = config('services.zomloa.api_key');
        $baseUrl = config('services.zomloa.base_url');
        $senderId = config('services.zomloa.sender_id');

        if (empty($apiKey)) {
            throw new Exception('Zomloa API key is not configured.');
        }

        try {
            $cleanRecipient = ltrim($recipient, '+');
            $resolvedCountryCode = $countryCode ?: $this->detectCountryCode($recipient);

            $response = Http::withHeaders([
                'X-Api-Key' => $apiKey,
                'Accept' => 'application/json',
            ])->post("{$baseUrl}/api/v1/gateway/sms/send", [
                'senderid' => $senderId,
                'countryCode' => $resolvedCountryCode,
                'mobiles' => $cleanRecipient,
                'sms' => $content,
            ]);

            if ($response->successful()) {
                $result = $response->json();

                // Zomloa peut renvoyer HTTP 200 mais avec un code d'erreur interne
                if (isset($result['responsecode']) && $result['responsecode'] !== '200') {
                    throw new Exception('Zomloa API logical error: '.json_encode($result));
                }

                // Vérifier si le message n'est pas rejeté immédiatement
                if (isset($result['status']) && in_array(strtoupper($result['status']), ['FAILED', 'REJECTED'])) {
                    throw new Exception('Zomloa API rejected the SMS instantly: '.json_encode($result));
                }

                Log::info('Zomloa SMS accepted by gateway (QUEUED), pending DLR', [
                    'recipient' => $recipient,
                    'messageId' => $result['messageId'] ?? null,
                    'status' => $result['status'] ?? 'UNKNOWN',
                    'response' => $result,
                ]);

                return $result;
            }

            Log::error('Zomloa SMS failed', [
                'recipient' => $recipient,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new Exception('Zomloa API returned an error: '.$response->status());
        } catch (Exception $e) {
            Log::error('Zomloa SMS exception', [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Detect country code from phone number prefix
     */
    public function detectCountryCode(string $phone): string
    {
        $clean = ltrim($phone, '+');
        if (str_starts_with($clean, '237')) {
            return 'CM';
        }
        if (str_starts_with($clean, '225')) {
            return 'CI';
        }
        if (str_starts_with($clean, '221')) {
            return 'SN';
        }
        if (str_starts_with($clean, '241')) {
            return 'GA';
        }
        if (str_starts_with($clean, '242')) {
            return 'CG';
        }
        if (str_starts_with($clean, '235')) {
            return 'TD';
        }
        if (str_starts_with($clean, '236')) {
            return 'CF';
        }
        if (str_starts_with($clean, '240')) {
            return 'GQ';
        }
        if (str_starts_with($clean, '229')) {
            return 'BJ';
        }
        if (str_starts_with($clean, '228')) {
            return 'TG';
        }
        if (str_starts_with($clean, '226')) {
            return 'BF';
        }
        if (str_starts_with($clean, '223')) {
            return 'ML';
        }
        if (str_starts_with($clean, '234')) {
            return 'NG';
        }
        if (str_starts_with($clean, '33')) {
            return 'FR';
        }
        if (str_starts_with($clean, '1')) {
            return 'US';
        }
        if (str_starts_with($clean, '44')) {
            return 'GB';
        }

        return 'CM';
    }
}
