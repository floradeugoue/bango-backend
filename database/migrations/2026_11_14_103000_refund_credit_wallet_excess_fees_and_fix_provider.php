<?php

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Models\Payment\PaymentTransaction;
use App\Services\Boost\CreditWalletService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /** @var CreditWalletService $creditWalletService */
        $creditWalletService = app(CreditWalletService::class);

        /** @var Collection<int, PaymentTransaction> $transactions */
        $transactions = PaymentTransaction::query()
            ->where(function ($q) {
                $q->where('channel', PaymentChannel::CreditWallet)
                    ->orWhere('external_reference', 'like', 'WALLET-%')
                    ->orWhere('reference', 'BNG-TX-20261020-A2N9VQNZ');
            })
            ->where('fee_amount', '>', 0)
            ->get();

        foreach ($transactions as $transaction) {
            $feeToRefund = (float) $transaction->fee_amount;

            DB::transaction(function () use ($transaction, $feeToRefund, $creditWalletService) {
                if ($feeToRefund > 0) {
                    $account = $transaction->user ?? $creditWalletService->getOrCreateWallet($transaction->user_id);
                    $creditWalletService->credit(
                        account: $account,
                        amount: $feeToRefund,
                        type: CreditTransactionType::Adjustment,
                        description: "Remboursement des frais opérateur indus ({$transaction->reference})",
                        idempotencyKey: "refund_wallet_fee_{$transaction->reference}",
                        reference: $transaction,
                        metadata: [
                            'refunded_fee' => $feeToRefund,
                            'transaction_reference' => $transaction->reference,
                            'reason' => 'Paiement en crédits sans frais d\'opérateur',
                        ],
                        source: 'pay'
                    );
                }

                $transaction->update([
                    'provider' => PaymentProvider::Wallet,
                    'fee_amount' => '0.00',
                    'total_amount' => $transaction->amount,
                    'amount_charged' => $transaction->amount,
                    'credit_used' => $transaction->amount,
                ]);

                $transaction->attempts()->update([
                    'provider' => PaymentProvider::Wallet,
                ]);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Pas de rollback pour une régularisation comptable
    }
};
