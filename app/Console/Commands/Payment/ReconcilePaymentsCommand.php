<?php

namespace App\Console\Commands\Payment;

use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Console\Command;

class ReconcilePaymentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment:reconcile';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Réconcilie les transactions de paiement en attente ou indéterminées avec Monetbil';

    /**
     * Execute the console command.
     */
    public function handle(PaymentReconciliationService $service): int
    {
        $this->info('Début de la réconciliation des paiements...');

        $stats = $service->reconcilePendingTransactions();

        $this->info(sprintf(
            'Réconciliation terminée. Scannées: %d, Confirmées: %d, Échouées: %d, Non résolues: %d, Ignorées: %d',
            $stats['scanned'],
            $stats['confirmed'],
            $stats['failed'],
            $stats['unresolved'],
            $stats['skipped']
        ));

        return Command::SUCCESS;
    }
}
