<?php

namespace App\Console\Commands\Visit;

use App\Services\Visit\VisitReservationService;
use Illuminate\Console\Command;

class SettleOverdueVisitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visits:settle-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Décline automatiquement les demandes de visite sans réponse après 18h et recrédite les prospects';

    /**
     * Execute the console command.
     */
    public function handle(VisitReservationService $reservationService): int
    {
        $this->info('Vérification des demandes de visite sans réponse depuis plus de 18h...');

        $count = $reservationService->settleOverdueUnanswered();

        $this->info("{$count} demande(s) traitée(s) et remboursée(s).");

        return self::SUCCESS;
    }
}
