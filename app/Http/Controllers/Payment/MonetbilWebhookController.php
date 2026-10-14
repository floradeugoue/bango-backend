<?php

namespace App\Http\Controllers\Payment;

use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment\PaymentTransaction;
use App\Services\Payment\Gateways\MonetbilGateway;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonetbilWebhookController extends Controller
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager,
        protected PaymentEngineService $paymentEngineService
    ) {}

    /**
     * POST /api/webhooks/payments/monetbil
     * Réception, vérification cryptographique et traitement du webhook IPN Monetbil.
     */
    public function handle(Request $request): JsonResponse
    {
        /** @var MonetbilGateway $gateway */
        $gateway = $this->gatewayManager->driver('monetbil');

        $allParams = $request->all();
        $incomingSign = (string) ($request->input('sign') ?? $request->header('X-Monetbil-Signature') ?? '');

        // 1. Contrôle timing-safe obligatoire de la signature cryptographique MD5
        if (empty($incomingSign) || ! $gateway->verifyWebhookSignature($allParams, $incomingSign)) {
            Log::warning('Webhook Monetbil rejeté : signature invalide ou absente', [
                'ip' => $request->ip(),
                'has_sign' => ! empty($incomingSign),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Signature invalide.',
            ], 403);
        }

        // 2. Validation stricte des données reçues (détection de notification mal formée)
        $paymentId = (string) ($request->input('paymentId') ?? $request->input('payment_id') ?? '');
        $itemRef = (string) ($request->input('item_ref') ?? $request->input('payment_ref') ?? '');

        if (empty($paymentId) && empty($itemRef)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Données de notification incomplètes : référence manquante.',
            ], 422);
        }

        if (! $request->has('amount') || ! is_numeric($request->input('amount'))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Données de notification incomplètes : montant invalide ou manquant.',
            ], 422);
        }

        $amount = (float) $request->input('amount');
        $currency = strtoupper((string) $request->input('currency', 'XAF'));
        $operator = (string) ($request->input('operator') ?? '');
        $operatorTxId = (string) ($request->input('operator_transaction_id') ?? '');

        // 3. Recherche et verrouillage pessimiste de la transaction sous transaction DB
        return DB::transaction(function () use (
            $itemRef,
            $paymentId,
            $amount,
            $currency,
            $operator,
            $operatorTxId,
            $gateway,
            $request
        ) {
            /** @var PaymentTransaction|null $transaction */
            $transaction = PaymentTransaction::query()
                ->where(function ($query) use ($itemRef, $paymentId) {
                    if (! empty($itemRef)) {
                        $query->where('reference', $itemRef);
                    }
                    if (! empty($paymentId)) {
                        $query->orWhere('external_reference', $paymentId);
                    }
                })
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                Log::warning('Webhook Monetbil : transaction introuvable', [
                    'paymentId' => $paymentId,
                    'item_ref' => $itemRef,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Transaction introuvable.',
                ], 404);
            }

            // 4. Idempotence absolue si la transaction est déjà confirmée
            if ($transaction->status === PaymentStatus::Confirmed) {
                Log::info('Webhook Monetbil reçu pour transaction déjà confirmée (idempotence)', [
                    'reference' => $transaction->reference,
                    'external_reference' => $transaction->external_reference,
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Paiement déjà confirmé.',
                ], 200);
            }

            // Si la transaction est déjà dans un état terminal (Failed, Cancelled, Refunded), ne rien faire
            if ($transaction->status->isTerminal()) {
                return response()->json([
                    'status' => 'received',
                    'message' => 'Transaction déjà finalisée.',
                ], 200);
            }

            // 5. Contrôle d'intégrité anti-fraude (concordance devise et montant total dû)
            if ($currency !== $transaction->currency) {
                $transaction->events()->create([
                    'event_type' => 'fraud_alert',
                    'source' => PaymentEventSource::Gateway->value,
                    'from_status' => $transaction->status->value,
                    'to_status' => $transaction->status->value,
                    'payload' => [
                        'reason' => 'Devise incohérente dans le webhook',
                        'expected' => $transaction->currency,
                        'received' => $currency,
                    ],
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Devise incohérente.',
                ], 422);
            }

            if (abs(((float) $transaction->total_amount) - $amount) > 0.01) {
                $transaction->events()->create([
                    'event_type' => 'fraud_alert',
                    'source' => PaymentEventSource::Gateway->value,
                    'from_status' => $transaction->status->value,
                    'to_status' => $transaction->status->value,
                    'payload' => [
                        'reason' => 'Montant incohérent dans le webhook',
                        'expected' => (float) $transaction->total_amount,
                        'received' => $amount,
                    ],
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Montant incohérent.',
                ], 422);
            }

            // 6. Double vérification serveur-à-serveur OBLIGATOIRE via checkPayment
            // Si la transaction n'avait pas encore la référence externe, on l'associe
            if (empty($transaction->external_reference) && ! empty($paymentId)) {
                $transaction->external_reference = $paymentId;
                $transaction->save();
            }

            $serverStatus = $gateway->checkStatus($transaction);

            // 7. Seule une réponse formellement CONFIRMED du serveur Monetbil autorise la confirmation
            if ($serverStatus->status === PaymentStatus::Confirmed) {
                $this->paymentEngineService->confirmAndFulfill(
                    transaction: $transaction,
                    externalReference: $paymentId ?: ($serverStatus->gatewayReference ?? $transaction->external_reference),
                    source: PaymentEventSource::Gateway,
                    fulfillmentDetails: [
                        'operator' => $operator ?: $serverStatus->operatorCode,
                        'operator_transaction_id' => $operatorTxId ?: $serverStatus->operatorReference,
                        'webhook_verified' => true,
                    ]
                );

                return response()->json([
                    'status' => 'success',
                    'message' => 'Paiement confirmé et prestation activée avec succès.',
                ], 200);
            }

            // 8. Échec formellement confirmé par le serveur Monetbil
            if ($serverStatus->status === PaymentStatus::Failed) {
                $this->paymentEngineService->failTransaction(
                    transaction: $transaction,
                    reason: $serverStatus->errorMessage ?? ($request->input('message') ?? 'Échec notifié par Monetbil'),
                    source: PaymentEventSource::Gateway,
                    details: [
                        'error_code' => $serverStatus->errorCode,
                        'operator' => $operator ?: $serverStatus->operatorCode,
                    ]
                );

                return response()->json([
                    'status' => 'received',
                    'message' => 'Statut d\'échec enregistré après vérification.',
                ], 200);
            }

            // 9. Statut indéterminé (Unknown / Timeout / Erreur temporaire 5xx)
            if ($serverStatus->status === PaymentStatus::Unknown) {
                if ($transaction->status !== PaymentStatus::Unknown) {
                    $transaction->transitionTo(
                        targetStatus: PaymentStatus::Unknown,
                        source: PaymentEventSource::Gateway,
                        reason: 'Vérification serveur Monetbil indéterminée (timeout ou indisponibilité)',
                        payload: [
                            'error_code' => $serverStatus->errorCode,
                            'error_message' => $serverStatus->errorMessage,
                        ]
                    );
                }

                return response()->json([
                    'status' => 'received',
                    'message' => 'Notification reçue mais statut serveur temporairement indéterminé.',
                ], 200);
            }

            // 10. Statut encore en cours (Processing / Pending)
            if ($transaction->status === PaymentStatus::Pending) {
                $transaction->transitionTo(
                    targetStatus: PaymentStatus::Processing,
                    source: PaymentEventSource::Gateway,
                    reason: 'Paiement en cours de validation par le client',
                    payload: ['paymentId' => $paymentId]
                );
            }

            return response()->json([
                'status' => 'received',
                'message' => 'Notification reçue, paiement en cours de traitement.',
            ], 200);
        });
    }
}
