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

        $baseUrl = rtrim(config('payment.gateways.monetbil.api_base_url', 'https://api.monetbil.com'), '/');
        $notifyUrl = config('payment.gateways.monetbil.notify_url') ?: route('api.webhooks.payments.monetbil');
        $phone = $this->normalizePhoneNumber($params['phone'] ?? $params['phonenumber'] ?? '');

        // Montant total figé, frais inclus
        $amount = (float) $transaction->total_amount;

        $payload = [
            'service' => $serviceKey,
            'phonenumber' => $phone,
            'amount' => $amount,
            'currency' => $transaction->currency ?: 'XAF',
            'country' => 'CM',
            'item_ref' => $transaction->reference,
            'payment_ref' => $transaction->reference,
            'user' => (string) $transaction->user_id,
            'notify_url' => $notifyUrl,
        ];

        if (! empty($params['operator'])) {
            $operatorCode = match (strtolower((string) $params['operator'])) {
                'orange', 'orange_money', 'cm_orangemoney' => 'CM_ORANGEMONEY',
                'mtn', 'mtn_momo', 'mobile_money', 'cm_mtn', 'cm_mtnmobilemoney' => 'CM_MTN',
                default => $params['operator'],
            };
            $payload['operator'] = $operatorCode;
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

            // Statut 1 ou 'success' = paiement validé avec succès
            if ($status === 1 || $status === '1' || strtolower((string) $status) === 'success') {
                return GatewayStatusResult::confirmed(
                    gatewayReference: $paymentId,
                    operatorReference: $txData['operator_transaction_id'] ?? ($txData['operator_reference'] ?? null),
                    operatorCode: $txData['operator'] ?? null,
                    raw: $data
                );
            }

            // Statut 0 ou 'failed' ou 'cancelled' = échec définitif ou annulation
            $isCancelledOrFailed = $status === 0
                || $status === '0'
                || in_array(strtolower((string) $status), ['failed', 'cancelled', 'canceled'], true)
                || in_array(strtolower((string) ($txData['message'] ?? '')), ['failed', 'cancelled', 'canceled'], true);

            if ($isCancelledOrFailed) {
                return GatewayStatusResult::failed(
                    errorCode: (string) ($txData['error'] ?? 'PAYMENT_CANCELLED'),
                    errorMessage: $txData['message'] ?? 'Le paiement a échoué ou a été annulé.',
                    gatewayReference: $paymentId,
                    raw: $data
                );
            }

            // Statut -1 ou 'pending' = toujours en cours de validation par le client
            if ($status === -1 || $status === '-1' || strtolower((string) $status) === 'pending') {
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
     * Normalise un numéro de téléphone au format attendu par Monetbil (indicatif pays sans '+').
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Si le numéro commence par 00, retirer les deux zéros
        if (str_starts_with($clean, '00')) {
            $clean = substr($clean, 2);
        }

        // Si 9 chiffres camerounais commençant par 6, ajouter 237
        if (strlen($clean) === 9 && str_starts_with($clean, '6')) {
            $clean = '237'.$clean;
        }

        return $clean;
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
