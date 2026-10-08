<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ZomloaSmsService
{
    /**
     * Send an OTP SMS via Zomloa API
     *
     * @param string $recipient
     * @param string $content
     * @return array|null
     * @throws Exception
     */
    public function send(string $recipient, string $content)
    {
        $apiKey = config('services.zomloa.api_key');
        $baseUrl = config('services.zomloa.base_url');
        $senderId = config('services.zomloa.sender_id');

        if (empty($apiKey)) {
            throw new Exception('Zomloa API key is not configured.');
        }

        try {
            $cleanRecipient = ltrim($recipient, '+');
            
            $response = Http::withHeaders([
                'X-Api-Key' => $apiKey,
                'Accept' => 'application/json',
            ])->post("{$baseUrl}/api/v1/gateway/sms/send", [
                'senderid' => $senderId,
                'countryCode' => 'CM',
                'mobiles' => $cleanRecipient,
                'sms' => $content,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                
                // Zomloa peut renvoyer HTTP 200 mais avec un code d'erreur interne
                if (isset($result['responsecode']) && $result['responsecode'] !== "200") {
                    throw new Exception('Zomloa API logical error: ' . json_encode($result));
                }

                // Vérifier si le message n'est pas rejeté immédiatement
                if (isset($result['status']) && in_array(strtoupper($result['status']), ['FAILED', 'REJECTED'])) {
                    throw new Exception('Zomloa API rejected the SMS instantly: ' . json_encode($result));
                }

                Log::info('Zomloa SMS accepted by gateway (QUEUED), pending DLR', [
                    'recipient' => $recipient,
                    'messageId' => $result['messageId'] ?? null,
                    'status'    => $result['status'] ?? 'UNKNOWN',
                    'response'  => $result
                ]);
                
                return $result;
            }

            Log::error('Zomloa SMS failed', [
                'recipient' => $recipient,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            throw new Exception('Zomloa API returned an error: ' . $response->status());

        } catch (Exception $e) {
            Log::error('Zomloa SMS exception', [
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
