<?php

namespace App\Console\Commands\Payment;

use App\Services\Admin\Finance\AdminPayoutService;
use Illuminate\Console\Command;

class ProcessScheduledPayoutsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payouts:process-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exécute les reversements aux bailleurs planifiés à J+1 08:00';

    /**
     * Execute the console command.
     */
    public function handle(AdminPayoutService $service): int
    {
        $this->info('Début du traitement des reversements aux bailleurs (Règle J+1 08:00)...');

        $count = $service->processScheduledPayouts();

        $this->info("Traitement terminé : {$count} reversement(s) exécuté(s).");

        return Command::SUCCESS;
    }
}
