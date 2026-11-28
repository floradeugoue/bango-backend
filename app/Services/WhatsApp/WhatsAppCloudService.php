<?php

namespace App\Services\WhatsApp;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCloudService
{
    /**
     * Send an OTP code via Meta WhatsApp Cloud API.
     *
     * @param  string  $phone  The recipient phone number in international format (+237...)
     * @param  string  $code  The 6-digit verification code
     * @return bool True if sent (or simulated in local/testing), false otherwise
     */
    public function sendOtp(string $phone, string $code): bool
    {
        $token = (string) Config::get('services.whatsapp.token');
        $phoneNumberId = (string) Config::get('services.whatsapp.phone_number_id');
        $templateName = (string) Config::get('services.whatsapp.template_name', 'bango_otp_code');
        $templateLang = (string) Config::get('services.whatsapp.template_lang', 'fr');

        // Normalize phone number: strip non-digits (Meta requires e.g. 237690000000 without '+')
        $cleanPhone = preg_replace('/\D/', '', $phone);

        // If credentials are not set in testing or local environment, simulate success
        if (empty($token) || empty($phoneNumberId)) {
            Log::info("WhatsApp Cloud API (Simulé) : Code {$code} envoyé au +{$cleanPhone}");

            return true;
        }

        try {
            $url = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $cleanPhone,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => $templateLang,
                    ],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [
                                [
                                    'type' => 'text',
                                    'text' => $code,
                                ],
                            ],
                        ],
                        [
                            'type' => 'button',
                            'sub_type' => 'url',
                            'index' => '0',
                            'parameters' => [
                                [
                                    'type' => 'text',
                                    'text' => $code,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $response = Http::withToken($token)
                ->timeout(10)
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::error('Erreur envoi WhatsApp Cloud API', [
                    'phone' => $cleanPhone,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return false;
            }

            return true;
        } catch (Exception $e) {
            Log::error('Exception lors de l\'envoi WhatsApp Cloud API : '.$e->getMessage(), [
                'phone' => $cleanPhone,
            ]);

            return false;
        }
    }
}
