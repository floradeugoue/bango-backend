<?php

namespace App\Services\Payment\Gateways;

use App\Exceptions\Payment\MissingPricingConfigurationException;
use App\Models\Payment\PaymentTransaction;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTO\GatewayInitiateResult;
use App\Services\Payment\DTO\GatewayStatusResult;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonetbilGateway implements PaymentGatewayInterface
{
    /**
     * Fake resolver utilisé pour les tests unitaires et d'intégration sans appel réseau.
     */
    protected static ?Closure $initiateFake = null;

    protected static ?Closure $statusFake = null;

    public function getIdentifier(): string
    {
        return 'monetbil';
    }

    public function isAvailable(): bool
    {
        $enabled = (bool) config('payment.gateways.monetbil.enabled', false);
        $serviceKey = config('payment.gateways.monetbil.service_key');
        $serviceSecret = config('payment.gateways.monetbil.service_secret');

        return $enabled && ! empty($serviceKey) && ! empty($serviceSecret);
    }

    public const SUPPORTED_COUNTRIES = ['CM', 'CI', 'SN', 'GA', 'BJ', 'TG'];

    public const COUNTRY_PHONE_CODES = [
        'CM' => '237',
        'CI' => '225',
        'SN' => '221',
        'GA' => '241',
        'BJ' => '229',
        'TG' => '228',
    ];

    public const COUNTRY_CURRENCIES = [
        'CM' => 'XAF',
        'GA' => 'XAF',
        'CI' => 'XOF',
        'SN' => 'XOF',
        'BJ' => 'XOF',
        'TG' => 'XOF',
    ];

    /**
     * Détermine le pays du paiement à partir du paramètre explicite, de l'indicatif du téléphone ou de l'utilisateur.
     */
    public function detectCountryCode(string $phone, ?PaymentTransaction $transaction = null, ?string $explicitCountry = null): ?string
    {
        if (! empty($explicitCountry)) {
            return strtoupper(trim($explicitCountry));
        }

        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($clean, '00')) {
            $clean = substr($clean, 2);
        }

        // Vérification par indicatif international dans le numéro
        foreach (self::COUNTRY_PHONE_CODES as $cCode => $prefix) {
            if (str_starts_with($clean, $prefix)) {
                return $cCode;
            }
        }

        // Fallback sur le pays de l'utilisateur
        if ($transaction && $transaction->relationLoaded('user') && $transaction->user?->country_code) {
            return strtoupper($transaction->user->country_code);
        }

        if ($transaction && $transaction->user_id) {
            $userCountry = $transaction->user?->country_code ?? null;
            if ($userCountry) {
                return strtoupper($userCountry);
            }
        }

        // Si devise XOF mais sans indicatif, fallback SN par défaut
        if ($transaction && strtoupper((string) $transaction->currency) === 'XOF') {
            return 'SN';
        }

        // Par défaut CM si devise XAF ou non spécifiée
        return 'CM';
    }

    /**
     * Initie un paiement Push USSD auprès de l'API Monetbil (v1/placePayment).
     */
    public function initiatePayment(PaymentTransaction $transaction, array $params = []): GatewayInitiateResult
    {
        if (static::$initiateFake !== null) {
            return (static::$initiateFake)($transaction, $params);
        }

        $serviceKey = config('payment.gateways.monetbil.service_key');
        if (empty($serviceKey)) {
            throw new MissingPricingConfigurationException('La clé de service Monetbil (service_key) n\'est pas configurée.');
        }

        $rawPhone = $params['phone'] ?? $params['phonenumber'] ?? '';
        $explicitCountry = $params['country'] ?? $params['country_code'] ?? null;
        $countryCode = $this->detectCountryCode($rawPhone, $transaction, $explicitCountry);

        // Validation du support pays par Monetbil
        if (! $countryCode || ! in_array($countryCode, self::SUPPORTED_COUNTRIES, true)) {
            return GatewayInitiateResult::failure(
                errorCode: 'COUNTRY_NOT_SUPPORTED',
                errorMessage: sprintf(
                    'Les paiements mobiles dans ce pays (%s) ne sont pas encore pris en charge par notre passerelle.',
                    $countryCode ?? 'INCONNU'
                ),
                raw: ['country' => $countryCode]
            );
        }

        $currency = self::COUNTRY_CURRENCIES[$countryCode] ?? ($transaction->currency ?: 'XAF');
        $baseUrl = rtrim(config('payment.gateways.monetbil.api_base_url', 'https://api.monetbil.com'), '/');
        $notifyUrl = config('payment.gateways.monetbil.notify_url') ?: route('api.webhooks.payments.monetbil');
        $phone = $this->normalizePhoneNumber($rawPhone, $countryCode);

        // Montant total figé, frais inclus
        $amount = (float) $transaction->total_amount;

        $payload = [
            'service' => $serviceKey,
            'phonenumber' => $phone,
            'amount' => $amount,
            'currency' => $currency,
            'country' => $countryCode,
            'item_ref' => $transaction->reference,
            'payment_ref' => $transaction->reference,
            'user' => (string) $transaction->user_id,
            'notify_url' => $notifyUrl,
        ];

        if (! empty($params['operator'])) {
            $operatorCode = $this->mapOperatorCode((string) $params['operator'], $countryCode);
            if (! empty($operatorCode)) {
                $payload['operator'] = $operatorCode;
            }
        }

        $timeout = (int) config('payment.gateways.monetbil.http_timeout', 15);
        $connectTimeout = (int) config('payment.gateways.monetbil.http_connect_timeout', 5);

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->post("{$baseUrl}/payment/v1/placePayment", $payload);

            if (! $response->successful()) {
                $errorData = $response->json() ?? [];
                $errorMessage = $errorData['message'] ?? 'Erreur lors de la requête Monetbil.';

                Log::warning('Échec de la requête Monetbil placePayment', [
                    'transaction_ref' => $transaction->reference,
                    'http_status' => $response->status(),
                    'error' => $errorMessage,
                ]);

                return GatewayInitiateResult::failure(
                    errorCode: 'GATEWAY_HTTP_'.$response->status(),
                    errorMessage: $errorMessage,
                    raw: $errorData
                );
            }

            $data = $response->json();
            if (! is_array($data)) {
                return GatewayInitiateResult::failure('MALFORMED_RESPONSE', 'Réponse Monetbil invalide (non-JSON).');
            }

            $status = strtoupper((string) ($data['status'] ?? ''));
            $isSuccess = $status === 'REQUEST_ACCEPTED' || $status === 'SUCCESS' || ($data['status'] ?? null) === 1;

            if ($isSuccess) {
                $paymentId = $data['paymentId'] ?? $data['payment_id'] ?? null;
                $channelUssd = $data['channel_ussd'] ?? null;
                $operatorCode = $data['operator'] ?? ($params['operator'] ?? null);

                return GatewayInitiateResult::success(
                    gatewayReference: (string) $paymentId,
                    operatorCode: $operatorCode,
                    ussdPrompt: $channelUssd,
                    timeoutSeconds: (int) config('payment.timeout_seconds', 120),
                    raw: $data
                );
            }

            return GatewayInitiateResult::failure(
                errorCode: $data['status'] ?? 'REQUEST_REJECTED',
                errorMessage: $data['message'] ?? 'Demande de paiement refusée par l\'opérateur.',
                raw: $data
            );
        } catch (ConnectionException $e) {
            Log::error('Timeout de connexion Monetbil placePayment', [
                'transaction_ref' => $transaction->reference,
                'exception' => $e->getMessage(),
            ]);

            return GatewayInitiateResult::failure('GATEWAY_TIMEOUT', 'Délai d\'attente dépassé lors de l\'appel à Monetbil.');
        } catch (Throwable $e) {
            Log::error('Erreur inattendue Monetbil placePayment', [
                'transaction_ref' => $transaction->reference,
                'exception' => $e->getMessage(),
            ]);

            return GatewayInitiateResult::failure('GATEWAY_ERROR', 'Une erreur réseau est survenue lors de l\'initialisation du paiement.');
        }
    }

    /**
     * Vérifie directement l'état d'une transaction auprès de l'API Monetbil (v1/checkPayment).
     */
    public function checkStatus(PaymentTransaction $transaction): GatewayStatusResult
    {
        if (static::$statusFake !== null) {
            return (static::$statusFake)($transaction);
        }

        $paymentId = $transaction->external_reference;
        if (empty($paymentId)) {
            return GatewayStatusResult::pending(raw: ['message' => 'Aucune référence externe Monetbil enregistrée.']);
        }

        $baseUrl = rtrim(config('payment.gateways.monetbil.api_base_url', 'https://api.monetbil.com'), '/');
        $timeout = (int) config('payment.gateways.monetbil.http_timeout', 15);
        $connectTimeout = (int) config('payment.gateways.monetbil.http_connect_timeout', 5);

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->post("{$baseUrl}/payment/v1/checkPayment", [
                    'paymentId' => $paymentId,
                ]);

            if (! $response->successful()) {
                $statusHttp = $response->status();
                $rawError = $response->json() ?? [];

                Log::warning('Échec HTTP lors de Monetbil checkPayment', [
                    'paymentId' => $paymentId,
                    'http_status' => $statusHttp,
                    'response' => $rawError,
                ]);

                // Les erreurs serveurs (5xx) ou timeout (408) représentent un état indéterminé
                if ($statusHttp >= 500 || $statusHttp === 408) {
                    return GatewayStatusResult::unknown(
                        gatewayReference: $paymentId,
                        errorCode: 'HTTP_'.$statusHttp,
                        errorMessage: 'Erreur temporaire du serveur Monetbil.',
                        raw: $rawError
                    );
                }

                return GatewayStatusResult::failed(
                    errorCode: 'HTTP_'.$statusHttp,
                    errorMessage: 'Impossible de vérifier le statut auprès de Monetbil.',
                    gatewayReference: $paymentId,
                    raw: $rawError
                );
            }

            $data = $response->json();
            if (! is_array($data)) {
                return GatewayStatusResult::unknown(
                    gatewayReference: $paymentId,
                    errorCode: 'MALFORMED_RESPONSE',
                    errorMessage: 'Réponse Monetbil invalide (non-JSON).',
                    raw: ['body' => $response->body()]
                );
            }

            $txData = $data['transaction'] ?? $data;
            $status = $txData['status'] ?? ($data['status'] ?? null);
            $msg = strtolower((string) ($txData['message'] ?? ($data['message'] ?? '')));
            $msg1 = strtolower((string) ($txData['message1'] ?? ($data['message1'] ?? '')));

            // Statut 1 ou 'success' = paiement validé avec succès
            if ($status === 1 || $status === '1' || strtolower((string) $status) === 'success' || str_contains($msg, 'success') || str_contains($msg, 'successful')) {
                return GatewayStatusResult::confirmed(
                    gatewayReference: $paymentId,
                    operatorReference: $txData['operator_transaction_id'] ?? ($txData['operator_reference'] ?? null),
                    operatorCode: $txData['operator'] ?? ($data['mobile_operator_code'] ?? null),
                    raw: $data
                );
            }

            // Statut 0 ou 'failed' ou 'cancelled' = échec définitif ou annulation
            $isCancelledOrFailed = $status === 0
                || $status === '0'
                || in_array(strtolower((string) $status), ['failed', 'cancelled', 'canceled'], true)
                || in_array($msg, ['failed', 'cancelled', 'canceled', 'expired'], true)
                || str_contains($msg, 'cancel')
                || str_contains($msg, 'failed')
                || str_contains($msg, 'expired');

            if ($isCancelledOrFailed) {
                return GatewayStatusResult::failed(
                    errorCode: (string) ($txData['error'] ?? 'PAYMENT_CANCELLED'),
                    errorMessage: $txData['message'] ?? ($data['message'] ?? 'Le paiement a échoué ou a été annulé.'),
                    gatewayReference: $paymentId,
                    raw: $data
                );
            }

            // Statut -1 ou 'pending' = toujours en cours de validation par le client
            if (
                $status === -1
                || $status === '-1'
                || strtolower((string) $status) === 'pending'
                || str_contains($msg, 'pending')
                || str_contains($msg, 'initiated')
                || str_contains($msg1, 'initiated')
            ) {
                return GatewayStatusResult::pending(
                    gatewayReference: $paymentId,
                    raw: $data
                );
            }

            // Statut non reconnu : conserver l'état incertain (unknown) sans conclure arbitrairement
            return GatewayStatusResult::unknown(
                gatewayReference: $paymentId,
                errorCode: 'UNKNOWN_GATEWAY_STATUS',
                errorMessage: 'Statut Monetbil non documenté ou incertain : '.(string) $status,
                raw: $data
            );
        } catch (ConnectionException $e) {
            Log::warning('Timeout réseau lors de Monetbil checkPayment', [
                'paymentId' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return GatewayStatusResult::unknown(
                gatewayReference: $paymentId,
                errorCode: 'GATEWAY_TIMEOUT',
                errorMessage: 'Délai d\'attente réseau dépassé lors de la vérification Monetbil.',
                raw: ['exception' => $e->getMessage()]
            );
        } catch (Throwable $e) {
            Log::error('Exception inattendue lors de Monetbil checkPayment', [
                'paymentId' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return GatewayStatusResult::unknown(
                gatewayReference: $paymentId,
                errorCode: 'CHECK_ERROR',
                errorMessage: 'Erreur inattendue lors de la vérification Monetbil : '.$e->getMessage(),
                raw: ['exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Vérifie la signature cryptographique MD5 du webhook Monetbil de manière timing-safe.
     *
     * @param  array<string, mixed>  $params  Paramètres reçus dans la requête webhook
     * @param  string|null  $incomingSignature  Signature 'sign' reçue
     */
    public function verifyWebhookSignature(array $params, ?string $incomingSignature): bool
    {
        if (empty($incomingSignature)) {
            return false;
        }

        $serviceSecret = config('payment.gateways.monetbil.service_secret');
        if (empty($serviceSecret)) {
            return false;
        }

        // 1. Extraire les paramètres sans la clé sign
        unset($params['sign']);

        // 2. Trier les clés par ordre alphabétique strict
        ksort($params);

        // 3. Concaténer la clé secrète suivie de toutes les valeurs
        $stringToHash = $serviceSecret.implode('', $params);

        // 4. Calculer l'empreinte MD5
        $calculatedSignature = md5($stringToHash);

        // 5. Comparaison résistante aux attaques temporelles
        return hash_equals(strtolower($calculatedSignature), strtolower($incomingSignature));
    }

    /**
     * Fait correspondre le code opérateur au format attendu par Monetbil pour chaque pays.
     */
    public function mapOperatorCode(string $operator, string $countryCode): ?string
    {
        $normalized = strtoupper(trim($operator));
        $op = strtolower(trim($operator));
        $prefix = strtoupper($countryCode);

        // Alias et codes legacy vers les codes complets officiels Monetbil
        $legacyAliases = [
            'CM_MTN' => 'CM_MTNMOBILEMONEY',
            'CI_MTN' => 'CI_MTNMOBILEMONEY',
            'BJ_MTN' => 'BJ_MTNMOBILEMONEY',
            'CI_MOOV' => 'CI_MOOVMONEY',
            'BJ_MOOV' => 'BJ_MOOVMONEY',
            'TG_MOOV' => 'TG_MOOVMONEY',
            'GA_MOOV' => 'GA_MOOVMONEY',
            'GA_AIRTEL' => 'GA_AIRTELMONEY',
        ];

        if (isset($legacyAliases[$normalized])) {
            return $legacyAliases[$normalized];
        }

        // Si l'opérateur a déjà le préfixe pays officiel
        if (str_starts_with($normalized, $prefix.'_')) {
            return $normalized;
        }

        $code = match ($prefix) {
            'CM' => match ($op) {
                'orange', 'orange_money', 'orange_cm' => 'CM_ORANGEMONEY',
                'mtn', 'mtn_momo', 'mobile_money', 'mtn_cm', 'mtnmobilemoney' => 'CM_MTNMOBILEMONEY',
                default => null,
            },
            'SN' => match ($op) {
                'orange', 'orange_money', 'orange_sn' => 'SN_ORANGEMONEY',
                'wave', 'wave_sn' => 'SN_WAVE',
                'free', 'free_money', 'free_sn', 'freemoney' => 'SN_FREE',
                default => null,
            },
            'CI' => match ($op) {
                'orange', 'orange_money', 'orange_ci' => 'CI_ORANGEMONEY',
                'mtn', 'mtn_momo', 'mtn_ci', 'mtnmobilemoney' => 'CI_MTNMOBILEMONEY',
                'moov', 'moov_money', 'moov_ci', 'moovmoney' => 'CI_MOOVMONEY',
                'wave', 'wave_ci' => 'CI_WAVE',
                default => null,
            },
            'GA' => match ($op) {
                'airtel', 'airtel_money', 'airtel_ga', 'airtelmoney' => 'GA_AIRTELMONEY',
                'moov', 'moov_money', 'moov_ga', 'moovmoney' => 'GA_MOOVMONEY',
                default => null,
            },
            'BJ' => match ($op) {
                'mtn', 'mtn_momo', 'mtn_bj', 'mtnmobilemoney' => 'BJ_MTNMOBILEMONEY',
                'moov', 'moov_money', 'moov_bj', 'moovmoney' => 'BJ_MOOVMONEY',
                default => null,
            },
            'TG' => match ($op) {
                'tmoney', 'tmoney_tg' => 'TG_TMONEY',
                'moov', 'moov_money', 'moov_tg', 'moovmoney' => 'TG_MOOVMONEY',
                default => null,
            },
            default => null,
        };

        if ($code !== null) {
            return $code;
        }

        // Si c'est déjà un code structuré complet (ex: XX_NOM), le transmettre tel quel
        if (preg_match('/^[A-Z]{2}_[A-Z0-9]+$/', $normalized)) {
            return $normalized;
        }

        // Sinon, ne pas transmettre pour permettre à Monetbil d'auto-détecter via le numéro
        return null;
    }

    /**
     * Normalise un numéro de téléphone au format attendu par Monetbil (indicatif pays sans '+').
     */
    public function normalizePhoneNumber(string $phone, ?string $countryCode = null): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Si le numéro commence par 00, retirer les deux zéros
        if (str_starts_with($clean, '00')) {
            $clean = substr($clean, 2);
        }

        // Si aucun indicatif pays n'est passé, tenter de le déduire
        $country = $countryCode ? strtoupper($countryCode) : null;
        if (! $country) {
            foreach (self::COUNTRY_PHONE_CODES as $cCode => $prefix) {
                if (str_starts_with($clean, $prefix)) {
                    $country = $cCode;
                    break;
                }
            }
        }

        $country = $country ?: 'CM';
        $countryPrefix = self::COUNTRY_PHONE_CODES[$country] ?? '237';

        // Si le numéro commence déjà par l'indicatif du pays, on le conserve tel quel
        if (str_starts_with($clean, $countryPrefix)) {
            return $clean;
        }

        // Normalisation spécifique par pays pour les numéros locaux sans indicatif
        return match ($country) {
            'CM' => (strlen($clean) === 9 && str_starts_with($clean, '6')) ? $countryPrefix.$clean : (strlen($clean) === 8 ? $countryPrefix.'6'.$clean : $countryPrefix.$clean),
            'SN' => (strlen($clean) === 9 && str_starts_with($clean, '7')) ? $countryPrefix.$clean : $countryPrefix.$clean,
            'CI' => (strlen($clean) === 10 && str_starts_with($clean, '0')) ? $countryPrefix.$clean : $countryPrefix.$clean,
            'GA' => ((strlen($clean) === 7 || strlen($clean) === 8)) ? $countryPrefix.ltrim($clean, '0') : $countryPrefix.$clean,
            'BJ', 'TG' => (strlen($clean) === 8) ? $countryPrefix.$clean : $countryPrefix.$clean,
            default => $countryPrefix.$clean,
        };
    }

    public static function fakeInitiate(?Closure $fake): void
    {
        static::$initiateFake = $fake;
    }

    public static function fakeStatus(?Closure $fake): void
    {
        static::$statusFake = $fake;
    }

    public static function resetFakes(): void
    {
        static::$initiateFake = null;
        static::$statusFake = null;
    }
}
