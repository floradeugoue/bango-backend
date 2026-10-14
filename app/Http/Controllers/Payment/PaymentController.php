<?php

namespace App\Http\Controllers\Payment;

use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\Concerns\FormatsPaymentResponses;
use App\Http\Requests\Payment\GetQuoteRequest;
use App\Http\Requests\Payment\InitiatePaymentRequest;
use App\Http\Resources\Payment\PaymentTransactionResource;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\SubscriptionPrice;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class PaymentController extends Controller
{
    use FormatsPaymentResponses;

    public function __construct(
        protected PricingService $pricingService,
        protected PaymentEngineService $paymentEngineService,
        protected PaymentGatewayManager $gatewayManager
    ) {}

    /**
     * GET /api/payments/catalog
     * Catalogue complet des offres, badges et prestations disponibles avec tarification et frais.
     */
    public function catalog(Request $request): JsonResponse
    {
        $gateway = $request->query('gateway');
        $catalog = $this->pricingService->getCatalog($gateway);

        return $this->paymentSuccess(
            data: $catalog,
            message: 'Catalogue des prestations payantes récupéré avec succès.'
        );
    }

    /**
     * POST /api/payments/quote
     * Établit un devis officiel certifié côté serveur avant engagement de paiement.
     */
    public function quote(GetQuoteRequest $request): JsonResponse
    {
        $user = $request->user();
        $serviceType = $request->getServiceType();

        // 1. Pour les abonnements, vérifier l'existence de l'offre
        if ($serviceType === PaymentServiceType::Subscription) {
            $priceId = $request->input('price_id');
            if (! $priceId) {
                return $this->paymentError(
                    code: 'INVALID_INPUT',
                    message: 'Un identifiant de tarif (price_id) est obligatoire pour une offre d\'abonnement.',
                    status: 422,
                    details: ['field' => 'price_id']
                );
            }

            /** @var SubscriptionPrice|null $price */
            $price = SubscriptionPrice::with('product')->find($priceId);
            if (! $price || ! $price->is_active || ! $price->isEffective()) {
                return $this->paymentError(
                    code: 'OFFER_NOT_FOUND',
                    message: 'Cette offre ou ce tarif n\'est plus disponible.',
                    status: 404,
                    details: ['price_id' => $priceId]
                );
            }

            // Vérifier si l'utilisateur a déjà un abonnement actif pour ce badge
            if ($user && $price->product && $price->product->badge_type) {
                $existing = $user->subscriptions()
                    ->where('badge_type', $price->product->badge_type)
                    ->where('state', 'active')
                    ->where('current_period_end', '>', now())
                    ->first();

                if ($existing) {
                    return $this->paymentError(
                        code: 'SERVICE_NOT_ELIGIBLE',
                        message: sprintf('Vous disposez déjà d\'un abonnement actif pour le badge "%s" (expire le %s).', $price->product->name, $existing->current_period_end->format('d/m/Y')),
                        status: 422,
                        details: [
                            'badge_type' => $price->product->badge_type,
                            'current_period_end' => $existing->current_period_end->toIso8601String(),
                        ]
                    );
                }
            }
        }

        // 2. Calcul du devis via PricingService
        $quote = $this->pricingService->calculateQuote(
            serviceType: $serviceType,
            priceId: $request->input('price_id'),
            customAmount: $request->getCustomAmount(),
            gateway: $request->getGateway()
        );

        $expiresAt = now()->addMinutes(15)->toIso8601String();
        $quoteToken = hash_hmac(
            'sha256',
            "{$quote->serviceType->value}|{$quote->price?->id}|{$quote->totalAmount}|{$expiresAt}",
            (string) config('app.key')
        );

        $responseData = [
            'service_type' => $quote->serviceType->value,
            'product' => $quote->product ? [
                'id' => $quote->product->id,
                'code' => $quote->product->code,
                'name' => $quote->product->name,
                'badge_type' => $quote->product->badge_type,
            ] : null,
            'price_id' => $quote->price?->id,
            'cadence' => $quote->price?->cadence,
            'currency' => $quote->currency,
            'base_amount' => (float) $quote->amount,
            'fee_amount' => (float) $quote->feeAmount,
            'tax_amount' => (float) $quote->taxAmount,
            'total_amount' => (float) $quote->totalAmount,
            'gateway' => $quote->gateway,
            'expires_at' => $expiresAt,
            'quote_token' => $quoteToken,
            'breakdown' => $quote->breakdown,
            'purchasable' => ! in_array($quote->serviceType, [PaymentServiceType::Certification, PaymentServiceType::Boost], true),
        ];

        return $this->paymentSuccess(
            data: $responseData,
            message: 'Devis certifié calculé avec succès.'
        );
    }

    /**
     * POST /api/payments/initiate
     * Initie un paiement Mobile Money (Push USSD) sécurisé par devis et idempotence.
     */
    public function initiate(InitiatePaymentRequest $request): JsonResponse
    {
        $user = $request->user();
        $serviceType = $request->getServiceType();

        // 1. Contrôles d'éligibilité et disponibilité métier
        if ($serviceType === PaymentServiceType::Certification) {
            return $this->paymentError(
                code: 'SERVICE_NOT_AVAILABLE',
                message: 'La prestation de certification Page Pro n\'est pas encore disponible à l\'achat.',
                status: 422,
                details: ['service_type' => 'certification']
            );
        }

        if ($serviceType === PaymentServiceType::Boost) {
            return $this->paymentError(
                code: 'SERVICE_NOT_AVAILABLE',
                message: 'La prestation de boost d\'annonce n\'est pas encore disponible à l\'achat.',
                status: 422,
                details: ['service_type' => 'boost']
            );
        }

        if ($serviceType === PaymentServiceType::Subscription) {
            $priceId = (int) $request->input('price_id');
            /** @var SubscriptionPrice|null $price */
            $price = SubscriptionPrice::with('product')->find($priceId);

            if (! $price || ! $price->is_active || ! $price->isEffective()) {
                return $this->paymentError(
                    code: 'OFFER_NOT_FOUND',
                    message: 'Cette offre ou ce tarif d\'abonnement n\'est plus disponible.',
                    status: 404,
                    details: ['price_id' => $priceId]
                );
            }

            // Vérifier si l'utilisateur possède déjà cet abonnement actif
            if ($price->product && $price->product->badge_type) {
                $existing = $user->subscriptions()
                    ->where('badge_type', $price->product->badge_type)
                    ->where('state', 'active')
                    ->where('current_period_end', '>', now())
                    ->first();

                if ($existing) {
                    return $this->paymentError(
                        code: 'SERVICE_NOT_ELIGIBLE',
                        message: sprintf('Vous disposez déjà d\'un abonnement actif pour le badge "%s" (expire le %s).', $price->product->name, $existing->current_period_end->format('d/m/Y')),
                        status: 422,
                        details: [
                            'badge_type' => $price->product->badge_type,
                            'current_period_end' => $existing->current_period_end->toIso8601String(),
                        ]
                    );
                }
            }
        }

        // 2. Calcul du devis certifié côté serveur (source de vérité immuable)
        $quote = $this->pricingService->calculateQuote(
            serviceType: $serviceType,
            priceId: $request->input('price_id'),
            customAmount: $request->getCustomAmount()
        );

        // 3. Vérification de cohérence avec un devis préalable (si transmis par le frontend)
        if ($request->filled('expected_amount') || $request->filled('expected_total')) {
            $expected = (float) ($request->input('expected_total') ?? $request->input('expected_amount'));
            if (abs($expected - (float) $quote->totalAmount) > 0.01) {
                return $this->paymentError(
                    code: 'PRICE_MODIFIED',
                    message: 'Le tarif a été actualisé. Veuillez accepter le nouveau devis avant confirmation.',
                    status: 409,
                    details: [
                        'expected_total' => $expected,
                        'current_total' => (float) $quote->totalAmount,
                    ]
                );
            }
        }

        if ($request->filled('quote_expires_at')) {
            $expiresAt = Carbon::parse($request->input('quote_expires_at'));
            if (now()->gt($expiresAt)) {
                return $this->paymentError(
                    code: 'QUOTE_EXPIRED',
                    message: 'Le devis précédent a expiré. Veuillez générer un nouveau devis.',
                    status: 409,
                    details: ['expired_at' => $expiresAt->toIso8601String()]
                );
            }
        }

        // 4. Création ou restitution de transaction par idempotence stricte
        try {
            $transaction = $this->paymentEngineService->createOrRetrieveTransaction(
                user: $user,
                quote: $quote,
                idempotencyKey: $request->getIdempotencyKey(),
                context: [
                    'phone' => $request->getPhone(),
                    'operator' => $request->input('operator'),
                    'channel' => $request->getChannel()->value,
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

        // Si la transaction a déjà été confirmée lors d'un appel précédent (rejeu idempotent)
        if ($transaction->status === PaymentStatus::Confirmed) {
            return $this->paymentSuccess(
                data: new PaymentTransactionResource($transaction),
                message: 'Cette transaction a déjà été confirmée avec succès.',
                status: 200
            );
        }

        // Si la transaction est déjà en cours de traitement (USSD déjà émis)
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

        // 5. Création de la tentative d'appel passerelle
        $attempt = $transaction->createAttempt(
            provider: PaymentProvider::Monetbil,
            channel: $request->getChannel(),
            externalReference: null,
            metadata: [
                'phone' => $request->getPhone(),
                'operator' => $request->input('operator'),
            ]
        );

        // 6. Appel de la passerelle active (Monetbil)
        $gateway = $this->gatewayManager->driver('monetbil');
        $initiateResult = $gateway->initiatePayment($transaction, [
            'phone' => $request->getPhone(),
            'operator' => $request->input('operator'),
        ]);

        if ($initiateResult->isSuccessful) {
            $attempt->markSuccessful(
                externalReference: $initiateResult->gatewayReference,
                metadata: ['ussd_prompt' => $initiateResult->ussdPrompt]
            );

            $transaction->external_reference = $initiateResult->gatewayReference;
            $transaction->transitionTo(
                targetStatus: PaymentStatus::Processing,
                source: PaymentEventSource::Gateway,
                reason: 'Demande USSD acceptée par Monetbil',
                payload: ['paymentId' => $initiateResult->gatewayReference]
            );

            $timeoutSeconds = (int) config('payment.timeout_seconds', 120);

            return $this->paymentSuccess(
                data: new PaymentTransactionResource($transaction),
                message: 'Demande de paiement envoyée sur le mobile.',
                status: 201,
                extra: [
                    'prompt' => [
                        'ussd' => $initiateResult->ussdPrompt,
                        'message' => $initiateResult->ussdPrompt,
                        'timeout_seconds' => $timeoutSeconds,
                        'expires_at' => $transaction->created_at->addSeconds($timeoutSeconds)->toIso8601String(),
                    ],
                ]
            );
        }

        // En cas d'échec de la passerelle
        $attempt->markFailed(
            errorCode: $initiateResult->errorCode ?? 'GATEWAY_ERROR',
            errorMessage: $initiateResult->errorMessage ?? 'Erreur lors de l\'appel à la passerelle'
        );

        if ($initiateResult->errorCode === 'GATEWAY_TIMEOUT') {
            return $this->paymentError(
                code: 'GATEWAY_UNAVAILABLE',
                message: 'Le serveur de paiement n\'a pas répondu dans le délai imparti. Veuillez réessayer.',
                status: 504,
                details: ['code' => 'EDGE-19'],
                retryable: true
            );
        }

        return $this->paymentError(
            code: 'PAYMENT_REFUSED',
            message: $initiateResult->errorMessage ?? 'La demande a été rejetée par l\'opérateur.',
            status: 400,
            details: ['error_code' => $initiateResult->errorCode],
            retryable: false
        );
    }

    /**
     * GET /api/payments/{id}/status
     * Polling et vérification du statut en temps réel (autorisé uniquement au propriétaire).
     */
    public function status(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::where('user_id', $user->id)
            ->where(function ($query) use ($id) {
                $query->where('id', $id)
                    ->orWhere('reference', $id);
            })
            ->first();

        if (! $transaction) {
            return $this->paymentError(
                code: 'TRANSACTION_NOT_FOUND',
                message: 'Transaction introuvable ou non autorisée.',
                status: 404
            );
        }

        // Si la transaction est en attente ou en cours, contre-vérification auprès de Monetbil
        if (in_array($transaction->status, [PaymentStatus::Pending, PaymentStatus::Processing, PaymentStatus::Unknown], true)) {
            $gateway = $this->gatewayManager->driver($transaction->provider->value);
            $checkResult = $gateway->checkStatus($transaction);

            if ($checkResult->status === PaymentStatus::Confirmed) {
                $this->paymentEngineService->confirmAndFulfill(
                    transaction: $transaction,
                    externalReference: $checkResult->gatewayReference ?? $transaction->external_reference,
                    source: PaymentEventSource::Gateway,
                    fulfillmentDetails: [
                        'operator_reference' => $checkResult->operatorReference,
                        'operator_code' => $checkResult->operatorCode,
                    ]
                );
            } elseif ($checkResult->status === PaymentStatus::Failed) {
                $this->paymentEngineService->failTransaction(
                    transaction: $transaction,
                    reason: $checkResult->errorMessage ?? 'Paiement échoué selon l\'opérateur',
                    source: PaymentEventSource::Gateway,
                    details: ['error_code' => $checkResult->errorCode]
                );
            } else {
                // Vérification du timeout (120 secondes depuis la création)
                $timeoutSeconds = (int) config('payment.timeout_seconds', 120);
                if ($transaction->created_at->diffInSeconds(Carbon::now()) >= $timeoutSeconds) {
                    $latestAttempt = $transaction->attempts()->latest('attempt_number')->first();
                    $latestAttempt?->markTimeout();

                    $this->paymentEngineService->failTransaction(
                        transaction: $transaction,
                        reason: 'Le délai maximal de 120 secondes est dépassé sans validation USSD.',
                        source: PaymentEventSource::System,
                        details: ['error_code' => 'PAYMENT_TIMEOUT']
                    );
                }
            }

            $transaction->refresh();
        }

        return $this->paymentSuccess(
            data: new PaymentTransactionResource($transaction)
        );
    }

    /**
     * POST /api/payments/{id}/cancel
     * Annulation manuelle d'une transaction par l'utilisateur.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::where('user_id', $user->id)
            ->where(function ($query) use ($id) {
                $query->where('id', $id)
                    ->orWhere('reference', $id);
            })
            ->first();

        if (! $transaction) {
            return $this->paymentError(
                code: 'TRANSACTION_NOT_FOUND',
                message: 'Transaction introuvable ou non autorisée.',
                status: 404
            );
        }

        if (! $transaction->canTransitionTo(PaymentStatus::Cancelled)) {
            return $this->paymentError(
                code: 'INVALID_STATE',
                message: sprintf('Cette transaction ne peut plus être annulée (statut actuel : %s).', $transaction->status->label()),
                status: 422
            );
        }

        $this->paymentEngineService->cancelTransaction(
            transaction: $transaction,
            source: PaymentEventSource::User,
            reason: 'Annulation demandée par l\'utilisateur'
        );

        $transaction->refresh();

        return $this->paymentSuccess(
            data: new PaymentTransactionResource($transaction),
            message: 'Transaction annulée avec succès.'
        );
    }

    /**
     * GET /api/payments/history
     * Historique paginé des transactions du client connecté avec filtres multi-critères.
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = PaymentTransaction::forUser($user)
            ->with(['product', 'price', 'subscription'])
            ->latest('created_at');

        // Filtre par statut
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filtre par type de prestation
        if ($request->filled('service_type')) {
            $query->where('service_type', $request->input('service_type'));
        } elseif ($request->filled('kind')) {
            $kind = $request->input('kind');
            if ($kind === 'plan') {
                $query->where('service_type', PaymentServiceType::Subscription->value);
            } else {
                $query->where('service_type', $kind);
            }
        }

        // Filtre famille (PAY-21: all, plan, boost, credit)
        if ($request->filled('filter')) {
            $filter = $request->input('filter');
            if ($filter === 'plan') {
                $query->where('service_type', PaymentServiceType::Subscription->value);
            } elseif ($filter === 'boost') {
                $query->where('service_type', PaymentServiceType::Boost->value);
            } elseif ($filter === 'credit') {
                $query->where('credit_used', '>', 0);
            }
        }

        // Filtre par période (from / to)
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', Carbon::parse($request->input('from')));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', Carbon::parse($request->input('to')));
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(50, max(1, $perPage));

        $transactions = $query->paginate($perPage);

        return PaymentTransactionResource::collection($transactions);
    }
}
