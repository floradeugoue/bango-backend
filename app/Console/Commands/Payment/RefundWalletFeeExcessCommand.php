<?php

namespace App\Console\Commands\Payment;

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Models\Payment\PaymentTransaction;
use App\Services\Boost\CreditWalletService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RefundWalletFeeExcessCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment:refund-wallet-fees {--dry-run : Simuler sans modifier la base de données}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rembourse les frais indus facturés sur les paiements par portefeuille de crédits BANGO et rectifie le provider';

    public function handle(CreditWalletService $creditWalletService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var Collection<int, PaymentTransaction> $transactions */
        $transactions = PaymentTransaction::query()
            ->where(function ($q) {
                $q->where('channel', PaymentChannel::CreditWallet)
                    ->orWhere('external_reference', 'like', 'WALLET-%')
                    ->orWhere('reference', 'BNG-TX-20261020-A2N9VQNZ');
            })
            ->where('fee_amount', '>', 0)
            ->get();

        if ($transactions->isEmpty()) {
            $this->info('Aucune transaction avec frais indus sur portefeuille à régulariser.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d transaction(s) trouvée(s) à régulariser.', $transactions->count()));

        foreach ($transactions as $transaction) {
            $feeToRefund = (float) $transaction->fee_amount;

            $this->line(sprintf(
                'Traitement transaction [%s] (User ID: %d, Montant: %s, Frais: %s)...',
                $transaction->reference,
                $transaction->user_id,
                $transaction->amount,
                $transaction->fee_amount
            ));

            if ($dryRun) {
                $this->warn("  [DRY-RUN] Crédits à rembourser : {$feeToRefund}, provider -> wallet, fee -> 0.00");

                continue;
            }

            DB::transaction(function () use ($transaction, $feeToRefund, $creditWalletService) {
                // 1. Rembourser les crédits pris en trop
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

                // 2. Corriger la transaction
                $transaction->update([
                    'provider' => PaymentProvider::Wallet,
                    'fee_amount' => '0.00',
                    'total_amount' => $transaction->amount,
                    'amount_charged' => $transaction->amount,
                    'credit_used' => $transaction->amount,
                ]);

                // 3. Corriger les tentatives associées
                $transaction->attempts()->update([
                    'provider' => PaymentProvider::Wallet,
                ]);
            });

            $this->info("  Transaction [{$transaction->reference}] régularisée : {$feeToRefund} crédits restitués.");
        }

        $this->info('Opération terminée avec succès.');

        return self::SUCCESS;
    }
}
