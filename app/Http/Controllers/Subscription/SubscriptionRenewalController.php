<?php

namespace App\Http\Controllers\Subscription;

use App\Enums\Payment\PaymentAttemptStatus;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payment\Concerns\FormatsPaymentResponses;
use App\Http\Resources\Payment\PaymentTransactionResource;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionRenewalController extends Controller
{
    use FormatsPaymentResponses;

    public function __construct(
        protected PricingService $pricingService,
        protected PaymentEngineService $paymentEngineService,
        protected PaymentGatewayManager $gatewayManager
    ) {}

    /**
     * GET /api/subscriptions/{id}/renewal
     * Fournit les informations complètes d'échéance et d'éligibilité au renouvellement manuel.
     */
    public function renewalInfo(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        /** @var Subscription|null $subscription */
        $subscription = $user->subscriptions()
            ->with(['product', 'price'])
            ->find($id);

        if (! $subscription) {
            return $this->paymentError(
                code: 'SUBSCRIPTION_NOT_FOUND',
                message: 'Abonnement introuvable ou vous n\'en êtes pas le titulaire.',
                status: 404
            );
        }

        // Vérifier l'éligibilité
        $isEligible = in_array($subscription->state, ['active', 'grace', 'expired'], true);
        $ineligibilityReason = null;

        if ($subscription->state === 'suspended') {
            $isEligible = false;
            $ineligibilityReason = 'Cet abonnement est actuellement suspendu administrativement et ne peut être renouvelé directement.';
        }

        // Calcul des jours restants / statut temporel
        $now = now();
        $daysRemaining = null;
        $graceDaysRemaining = null;

        if ($subscription->current_period_end) {
            $daysRemaining = $now->diffInDays($subscription->current_period_end, false);
        }

        if ($subscription->state === 'grace' && $subscription->grace_until) {
            $graceDaysRemaining = max(0, $now->diffInDays($subscription->grace_until, false));
        }

        // Récupérer le tarif actuel en vigueur pour ce produit et cette cadence
        $currentActivePrice = $subscription->product->activePrices()
            ->where('cadence', $subscription->cadence)
            ->first();

        // Récupérer les options de tarifs disponibles pour ce produit
        $availablePrices = $subscription->product->activePrices()
            ->get()
            ->map(fn (SubscriptionPrice $p) => [
                'price_id' => $p->id,
                'cadence' => $p->cadence,
                'amount' => (float) $p->price_amount,
                'currency' => $p->currency,
                'duration_days' => $p->duration_days,
                'savings' => $p->savings_description,
            ]);

        return $this->paymentSuccess(
            data: [
                'subscription' => [
                    'id' => (string) $subscription->id,
                    'badge_type' => $subscription->badge_type,
                    'state' => $subscription->state,
                    'cadence' => $subscription->cadence,
                    'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                    'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                    'grace_until' => $subscription->grace_until?->toIso8601String(),
                    'days_remaining' => $daysRemaining,
                    'grace_days_remaining' => $graceDaysRemaining,
                    'auto_renew' => (bool) $subscription->auto_renew,
                ],
                'product' => [
                    'id' => (string) $subscription->product->id,
                    'code' => $subscription->product->code,
                    'name' => $subscription->product->name,
                    'description' => $subscription->product->description,
                ],
                'renewal_price' => $currentActivePrice ? [
                    'price_id' => $currentActivePrice->id,
                    'cadence' => $currentActivePrice->cadence,
                    'amount' => (float) $currentActivePrice->price_amount,
                    'currency' => $currentActivePrice->currency,
                    'duration_days' => $currentActivePrice->duration_days,
                ] : null,
                'available_pricing_options' => $availablePrices,
                'is_eligible_for_renewal' => $isEligible,
                'ineligibility_reason' => $ineligibilityReason,
                'requires_manual_confirmation' => true,
            ],
            message: 'Informations de renouvellement récupérées avec succès.'
        );
    }

    /**
     * POST /api/subscriptions/{id}/renewal/quote
     * Calcule le devis officiel certifié serveur pour le renouvellement.
     */
    public function quote(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        /** @var Subscription|null $subscription */
        $subscription = $user->subscriptions()
            ->with(['product'])
            ->find($id);

        if (! $subscription) {
            return $this->paymentError(
                code: 'SUBSCRIPTION_NOT_FOUND',
                message: 'Abonnement introuvable ou vous n\'en êtes pas le titulaire.',
                status: 404
            );
        }

        if ($subscription->state === 'suspended') {
            return $this->paymentError(
                code: 'SUBSCRIPTION_SUSPENDED',
                message: 'Cet abonnement est suspendu et ne peut faire l\'objet d\'un devis de renouvellement.',
                status: 422
            );
        }

        // Cadence choisie ou cadence actuelle de l'abonnement
        $requestedCadence = $request->input('cadence', $subscription->cadence);

        /** @var SubscriptionPrice|null $price */
        $price = $subscription->product->activePrices()
            ->where('cadence', $requestedCadence)
            ->first();

        if (! $price || ! $price->isEffective()) {
            return $this->paymentError(
                code: 'OFFER_NOT_FOUND',
                message: "Le tarif pour la cadence '{$requestedCadence}' n'est pas disponible actuellement.",
                status: 422
            );
        }

        // Calcul du devis certifié serveur
        $gatewayName = $request->input('gateway', 'monetbil');
        $quote = $this->pricingService->calculateQuote(
            serviceType: PaymentServiceType::Subscription,
            priceId: $price->id,
            gateway: $gatewayName
        );

        return $this->paymentSuccess(
            data: [
                'subscription_id' => (string) $subscription->id,
                'product' => [
                    'id' => (string) $subscription->product->id,
                    'name' => $subscription->product->name,
                    'code' => $subscription->product->code,
                ],
                'price' => [
                    'id' => $price->id,
                    'cadence' => $price->cadence,
                    'amount' => (float) $price->price_amount,
                    'currency' => $price->currency,
                ],
                'pricing' => [
                    'base_amount' => (float) $quote->amount,
                    'fee_amount' => (float) $quote->feeAmount,
                    'tax_amount' => (float) $quote->taxAmount,
                    'total_amount' => (float) $quote->totalAmount,
                    'currency' => $quote->currency,
                    'gateway' => $quote->gateway,
                    'snapshot' => $quote->snapshot,
                ],
                'requires_manual_confirmation' => true,
            ],
            message: 'Devis de renouvellement certifié établi avec succès.'
        );
    }

    /**
     * POST /api/subscriptions/{id}/renewal/confirm
     * Confirmation explicite de l'utilisateur et déclenchement du paiement Mobile Money.
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        /** @var Subscription|null $subscription */
        $subscription = $user->subscriptions()
            ->with(['product'])
            ->find($id);

        if (! $subscription) {
            return $this->paymentError(
                code: 'SUBSCRIPTION_NOT_FOUND',
                message: 'Abonnement introuvable ou vous n\'en êtes pas le titulaire.',
                status: 404
            );
        }

        if ($subscription->state === 'suspended') {
            return $this->paymentError(
                code: 'SUBSCRIPTION_SUSPENDED',
                message: 'Cet abonnement est suspendu et ne peut être renouvelé.',
                status: 422
            );
        }

        $validated = $request->validate([
            'operator' => ['required', 'string', 'in:MTN,ORANGE,orange,mtn'],
            'phone' => ['required', 'string', 'min:9', 'max:15'],
            'cadence' => ['nullable', 'string', 'in:monthly,yearly'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $cadence = $validated['cadence'] ?? $subscription->cadence;

        /** @var SubscriptionPrice|null $price */
        $price = $subscription->product->activePrices()
            ->where('cadence', $cadence)
            ->first();

        if (! $price || ! $price->isEffective()) {
            return $this->paymentError(
                code: 'OFFER_NOT_FOUND',
                message: 'Le tarif applicable au renouvellement est introuvable ou inactif.',
                status: 422
            );
        }

        // 1. Protection contre les paiements concurrents :
        // Vérifier si une transaction récente est déjà en cours (processing) pour cet abonnement
        $pendingTransaction = PaymentTransaction::where('subscription_id', $subscription->id)
            ->where('status', PaymentStatus::Processing)
            ->where('created_at', '>=', now()->subSeconds((int) config('payment.timeout_seconds', 120)))
            ->latest('created_at')
            ->first();

        if ($pendingTransaction) {
            return $this->paymentSuccess(
                data: new PaymentTransactionResource($pendingTransaction),
                message: 'Une demande de renouvellement est déjà en cours de traitement sur votre téléphone.',
                status: 200,
                extra: [
                    'prompt' => [
                        'message' => 'Veuillez valider le message USSD sur votre téléphone.',
                    ],
                ]
            );
        }

        // 2. Établissement du devis officiel côté serveur
        $gatewayName = 'monetbil';
        $quote = $this->pricingService->calculateQuote(
            serviceType: PaymentServiceType::Subscription,
            priceId: $price->id,
            gateway: $gatewayName
        );

        // Clé d'idempotence pour la transaction
        $idempotencyKey = $validated['idempotency_key'] ?? ('renewal_sub_'.$subscription->id.'_'.(string) Str::uuid());

        // 3. Création atomique de la transaction de paiement liée à l'abonnement
        $transaction = $this->paymentEngineService->createOrRetrieveTransaction(
            user: $user,
            quote: $quote,
            idempotencyKey: $idempotencyKey,
            context: [
                'subscription_id' => $subscription->id,
                'channel' => 'mobile_money',
                'context' => 'manual_renewal',
                'previous_period_end' => $subscription->current_period_end?->toIso8601String(),
                'state_at_renewal' => $subscription->state,
            ]
        );

        // 4. Création de la tentative d'appel passerelle
        $attempt = $transaction->createAttempt(
            provider: PaymentProvider::Monetbil,
            channel: PaymentChannel::MobileMoney,
            metadata: [
                'phone' => $validated['phone'],
                'operator' => strtoupper($validated['operator']),
            ]
        );

        // 5. Déclenchement de l'appel à la passerelle Monetbil
        $gateway = $this->gatewayManager->driver($gatewayName);
        $result = $gateway->initiatePayment($transaction, [
            'phone' => $validated['phone'],
            'operator' => strtoupper($validated['operator']),
        ]);

        if (! $result->isSuccessful) {
            $attempt->markFailed(
                errorCode: $result->errorCode ?? 'GATEWAY_ERROR',
                errorMessage: $result->message ?? 'Échec lors de l\'initialisation chez l\'opérateur.'
            );

            $this->paymentEngineService->failTransaction(
                transaction: $transaction,
                reason: $result->message ?? 'Échec de la demande de paiement chez l\'opérateur.',
                details: ['error_code' => $result->errorCode]
            );

            return $this->paymentError(
                code: $result->errorCode ?? 'GATEWAY_ERROR',
                message: $result->message ?? 'Impossible d\'initier la demande de paiement chez l\'opérateur.',
                status: 502
            );
        }

        // 6. Succès de l'initiation du Push USSD
        $attempt->update([
            'external_reference' => $result->gatewayReference,
            'status' => PaymentAttemptStatus::Pending,
        ]);

        $transaction->external_reference = $result->gatewayReference;
        $transaction->transitionTo(
            targetStatus: PaymentStatus::Processing,
            source: PaymentEventSource::Gateway,
            reason: 'Demande USSD de renouvellement acceptée par Monetbil',
            payload: ['paymentId' => $result->gatewayReference]
        );

        $transaction->refresh();

        return $this->paymentSuccess(
            data: new PaymentTransactionResource($transaction),
            message: 'Demande de renouvellement transmise avec succès à votre opérateur Mobile Money.',
            status: 201,
            extra: [
                'prompt' => [
                    'instruction' => 'Un message de confirmation USSD va apparaître sur votre téléphone.',
                    'phone' => $validated['phone'],
                    'operator' => strtoupper($validated['operator']),
                    'amount' => (float) $transaction->total_amount,
                    'currency' => $transaction->currency,
                    'expires_at' => $transaction->created_at->addSeconds((int) config('payment.timeout_seconds', 120))->toIso8601String(),
                ],
            ]
        );
    }
}
