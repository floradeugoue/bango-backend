<?php

namespace App\Http\Controllers\Credits;

use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\Concerns\FormatsPaymentResponses;
use App\Http\Requests\Credits\StoreCreditPurchaseRequest;
use App\Http\Resources\Payment\PaymentTransactionResource;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PricingService;
use Illuminate\Http\JsonResponse;

class CreditPurchaseController extends Controller
{
    use FormatsPaymentResponses;

    public function __construct(
        protected PricingService $pricingService,
        protected PaymentEngineService $paymentEngineService,
        protected PaymentGatewayManager $gatewayManager
    ) {}

    /**
     * Initie un achat de Crédits BANGO via le module PAY (Mobile Money).
     * Règle stricte : Le portefeuille n'est JAMAIS crédité à l'initiation.
     *
     * POST /api/credits/purchases
     */
    public function store(StoreCreditPurchaseRequest $request): JsonResponse
    {
        $user = $request->user();
        $amount = $request->getAmount();

        // 1. Calcul du devis certifié côté serveur (1 FCFA = 1 Crédit BANGO)
        $quote = $this->pricingService->calculateQuote(
            serviceType: PaymentServiceType::Credit,
            customAmount: $amount
        );

        // 2. Création ou restitution de la transaction PAY sous idempotence stricte
        try {
            $transaction = $this->paymentEngineService->createOrRetrieveTransaction(
                user: $user,
                quote: $quote,
                idempotencyKey: $request->getIdempotencyKey(),
                context: [
                    'phone' => $request->getPhone(),
                    'operator' => $request->getOperator(),
                    'channel' => $request->getChannel()->value,
                    'credits_to_credit' => $amount,
                    'client_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]
            );
        } catch (IdempotencyConflictException $e) {
            return $this->paymentError(
                code: 'IDEMPOTENCY_CONFLICT',
                message: $e->getMessage(),
                status: 409,
                details: ['code' => 'EDGE-17']
            );
        }

        // Si la transaction a déjà été confirmée lors d'un appel antérieur
        if ($transaction->status === PaymentStatus::Confirmed) {
            return $this->paymentSuccess(
                data: new PaymentTransactionResource($transaction),
                message: 'Cette transaction d\'achat a déjà été confirmée avec succès.',
                status: 200
            );
        }

        // Si la transaction est déjà en cours de validation USSD
        if ($transaction->status === PaymentStatus::Processing) {
            $timeoutSeconds = (int) config('payment.timeout_seconds', 120);
            $expiresAt = $transaction->created_at->addSeconds($timeoutSeconds);
            $remainingSeconds = max(0, $expiresAt->diffInSeconds(now()));

            return $this->paymentSuccess(
                data: new PaymentTransactionResource($transaction),
                message: 'Une demande de paiement est déjà en cours de validation.',
                status: 200,
                extra: [
                    'prompt' => [
                        'timeout_seconds' => $timeoutSeconds,
                        'remaining_seconds' => $remainingSeconds,
                        'expires_at' => $expiresAt->toIso8601String(),
                    ],
                ]
            );
        }

        // 3. Création de la tentative d'appel passerelle
        $attempt = $transaction->createAttempt(
            provider: PaymentProvider::Monetbil,
            channel: $request->getChannel(),
            externalReference: null,
            metadata: [
                'phone' => $request->getPhone(),
                'operator' => $request->getOperator(),
            ]
        );

        // 4. Appel de la passerelle de paiement
        $gateway = $this->gatewayManager->driver('monetbil');
        $initiateResult = $gateway->initiatePayment($transaction, [
            'phone' => $request->getPhone(),
            'operator' => $request->getOperator(),
        ]);

        if ($initiateResult->isSuccessful) {
            $attempt->markSuccessful(
                externalReference: $initiateResult->gatewayReference,
                metadata: ['ussd_prompt' => $initiateResult->ussdPrompt]
            );

            return $this->paymentSuccess(
                data: new PaymentTransactionResource($transaction->fresh()),
                message: 'Demande de paiement Mobile Money initiée avec succès.',
                status: 201,
                extra: [
                    'prompt' => [
                        'ussd_code' => $initiateResult->ussdPrompt,
                        'timeout_seconds' => (int) config('payment.timeout_seconds', 120),
                        'instruction' => 'Veuillez valider l\'opération sur votre téléphone en saisissant votre code secret Mobile Money.',
                    ],
                    'conversion' => [
                        'amount_fcfa' => $amount,
                        'credits_awarded' => $amount,
                        'rate' => '1 FCFA = 1 Crédit BANGO',
                    ],
                ]
            );
        }

        $attempt->markFailed(
            failureCode: $initiateResult->errorCode ?? 'GATEWAY_ERROR',
            failureMessage: $initiateResult->errorMessage ?? 'Échec d\'initialisation du paiement.'
        );

        return $this->paymentError(
            code: 'GATEWAY_INITIATE_FAILED',
            message: $initiateResult->errorMessage ?? 'Impossible d\'initier la demande de paiement Mobile Money.',
            status: 502,
            details: [
                'provider' => 'monetbil',
                'gateway_code' => $initiateResult->errorCode,
            ]
        );
    }
}
