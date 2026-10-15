<?php

namespace App\Console\Commands\Visit;

use App\Services\Visit\VisitReservationService;
use Illuminate\Console\Command;

class ExpirePendingVisitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visits:expire-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire automatiquement les verrous temporaires de 15 minutes non réglés et libère les créneaux';

    /**
     * Execute the console command.
     */
    public function handle(VisitReservationService $reservationService): int
    {
        $this->info('Vérification des verrous de visite expirés...');

        $count = $reservationService->expirePendingReservations();

        $this->info("{$count} réservation(s) expirée(s) et capacité libérée.");

        return self::SUCCESS;
    }
}
