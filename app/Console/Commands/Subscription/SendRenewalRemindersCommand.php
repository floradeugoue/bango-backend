<?php

namespace App\Console\Commands\Subscription;

use App\Services\Subscription\SubscriptionRenewalService;
use Illuminate\Console\Command;

class SendRenewalRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscription:send-renewal-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envoie les notifications de rappel avant échéance (J-7, J-3, J-1) de manière idempotente';

    /**
     * Execute the console command.
     */
    public function handle(SubscriptionRenewalService $renewalService): int
    {
        $this->info('Début de l\'envoi des rappels d\'échéance de renouvellement...');

        $stats = $renewalService->sendUpcomingReminders();

        $this->info("Rappels analysés : {$stats['checked']} | Envoyés : {$stats['sent']} | Déjà notifiés (ignorés) : {$stats['skipped']}");

        return Command::SUCCESS;
    }
}
