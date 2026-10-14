<?php

namespace App\Services\Payment;

use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentReconciliationService
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager,
        protected PaymentEngineService $paymentEngineService
    ) {}

    /**
     * Réconcilie les transactions non finalisées (pending, processing, unknown).
     *
     * @return array{scanned: int, confirmed: int, failed: int, unresolved: int, skipped: int}
     */
    public function reconcilePendingTransactions(): array
    {
        $minAgeMinutes = (int) config('payment.reconciliation.min_age_minutes', 5);
        $maxAgeDays = (int) config('payment.reconciliation.max_age_days', 3);
        $maxRetries = (int) config('payment.reconciliation.max_retries', 5);
        $batchSize = (int) config('payment.reconciliation.batch_size', 50);

        $thresholdOlderThan = Carbon::now()->subMinutes($minAgeMinutes);
        $thresholdNewerThan = Carbon::now()->subDays($maxAgeDays);

        /** @var Collection<int, PaymentTransaction> $transactions */
        $transactions = PaymentTransaction::query()
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Processing->value,
                PaymentStatus::Unknown->value,
            ])
            ->whereNotNull('external_reference')
            ->where('created_at', '<=', $thresholdOlderThan)
            ->where('created_at', '>=', $thresholdNewerThan)
            ->orderBy('id', 'asc')
            ->limit($batchSize)
            ->get();

        $stats = [
            'scanned' => $transactions->count(),
            'confirmed' => 0,
            'failed' => 0,
            'unresolved' => 0,
            'skipped' => 0,
        ];

        foreach ($transactions as $transaction) {
            $meta = $transaction->metadata ?? [];
            $attempts = (int) ($meta['reconciliation_attempts'] ?? 0);

            if ($attempts >= $maxRetries) {
                $stats['skipped']++;

                continue;
            }

            try {
                $result = $this->reconcileTransaction($transaction);
                match ($result) {
                    PaymentStatus::Confirmed => $stats['confirmed']++,
                    PaymentStatus::Failed => $stats['failed']++,
                    default => $stats['unresolved']++,
                };
            } catch (Throwable $e) {
                Log::error('Erreur lors de la réconciliation de la transaction', [
                    'transaction_ref' => $transaction->reference,
                    'error' => $e->getMessage(),
                ]);
                $stats['unresolved']++;
            }
        }

        return $stats;
    }

    /**
     * Réconcilie une transaction unique de manière sécurisée sous verrou pessimiste.
     */
    public function reconcileTransaction(PaymentTransaction $transaction): PaymentStatus
    {
        return DB::transaction(function () use ($transaction) {
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Si la transaction a été confirmée ou finalisée entre-temps
            if ($lockedTx->status === PaymentStatus::Confirmed) {
                return PaymentStatus::Confirmed;
            }

            if ($lockedTx->status->isTerminal()) {
                return $lockedTx->status;
            }

            $meta = $lockedTx->metadata ?? [];
            $meta['reconciliation_attempts'] = ((int) ($meta['reconciliation_attempts'] ?? 0)) + 1;
            $meta['last_reconciled_at'] = Carbon::now()->toIso8601String();
            $lockedTx->metadata = $meta;
            $lockedTx->save();

            $driverName = $lockedTx->provider->value;
            $gateway = $this->gatewayManager->driver($driverName);

            $statusResult = $gateway->checkStatus($lockedTx);

            if ($statusResult->status === PaymentStatus::Confirmed) {
                $this->paymentEngineService->confirmAndFulfill(
                    transaction: $lockedTx,
                    externalReference: $statusResult->gatewayReference ?? $lockedTx->external_reference,
                    source: PaymentEventSource::System,
                    fulfillmentDetails: [
                        'reconciliation' => true,
                        'operator' => $statusResult->operatorCode,
                        'operator_transaction_id' => $statusResult->operatorReference,
                    ]
                );

                return PaymentStatus::Confirmed;
            }

            if ($statusResult->status === PaymentStatus::Failed) {
                $this->paymentEngineService->failTransaction(
                    transaction: $lockedTx,
                    reason: $statusResult->errorMessage ?? 'Échec confirmé lors de la réconciliation serveur',
                    source: PaymentEventSource::System,
                    details: [
                        'reconciliation' => true,
                        'error_code' => $statusResult->errorCode,
                    ]
                );

                return PaymentStatus::Failed;
            }

            if ($statusResult->status === PaymentStatus::Unknown && $lockedTx->status !== PaymentStatus::Unknown) {
                $lockedTx->transitionTo(
                    targetStatus: PaymentStatus::Unknown,
                    source: PaymentEventSource::System,
                    reason: 'Statut fournisseur toujours indéterminé lors de la réconciliation',
                    payload: [
                        'reconciliation' => true,
                        'error_code' => $statusResult->errorCode,
                    ]
                );
            }

            return $lockedTx->status;
        });
    }
}
