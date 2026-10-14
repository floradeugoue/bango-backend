<?php

namespace App\Console\Commands\Subscription;

use App\Services\Subscription\SubscriptionRenewalService;
use Illuminate\Console\Command;

class ProcessSubscriptionLifecycleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:process-lifecycle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Traite le cycle de vie des abonnements (passage en grâce de 7 jours, puis expiration idempotente avec retrait de badge)';

    /**
     * Execute the console command.
     */
    public function handle(SubscriptionRenewalService $renewalService): int
    {
        $this->info('Traitement du cycle de vie des abonnements (grâce & expiration)...');

        $stats = $renewalService->processLifecycle();

        $this->info("Abonnements passés en période de grâce : {$stats['entered_grace']} | Abonnements définitivement expirés : {$stats['expired']}");

        return Command::SUCCESS;
    }
}
