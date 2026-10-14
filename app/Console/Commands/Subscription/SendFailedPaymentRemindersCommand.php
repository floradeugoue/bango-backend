<?php

namespace App\Console\Commands\Subscription;

use App\Services\Subscription\SubscriptionRenewalService;
use Illuminate\Console\Command;

class SendFailedPaymentRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:send-failed-payment-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envoie les relances après un échec de paiement (24h, 72h) sans déclencher de débit automatique';

    /**
     * Execute the console command.
     */
    public function handle(SubscriptionRenewalService $renewalService): int
    {
        $this->info('Vérification des transactions en échec pour relance...');

        $stats = $renewalService->sendFailedPaymentRetries();

        $this->info("Transactions analysées : {$stats['checked']} | Relances envoyées : {$stats['sent']} | Ignorées/En attente : {$stats['skipped']}");

        return Command::SUCCESS;
    }
}
