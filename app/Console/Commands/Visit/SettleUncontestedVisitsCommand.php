<?php

namespace App\Console\Commands\Visit;

use App\Services\Visit\VisitReservationService;
use Illuminate\Console\Command;

class SettleUncontestedVisitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visits:settle-uncontested';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clôture les visites émargées par le bailleur sans contestation sous 24h et programme leur reversement';

    /**
     * Execute the console command.
     */
    public function handle(VisitReservationService $reservationService): int
    {
        $this->info('Vérification des visites émargées sans contestation sous 24h...');

        $count = $reservationService->settleUncontestedVisits();

        $this->info("{$count} visite(s) actée(s) 'visited' et reversement(s) programmé(s).");

        return self::SUCCESS;
    }
}
