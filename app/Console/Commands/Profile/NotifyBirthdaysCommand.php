<?php

namespace App\Console\Commands\Profile;

use App\Services\Profile\BirthdayNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyBirthdaysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'birthdays:notify
                            {--hour=9 : Heure locale cible dans le fuseau de l\'utilisateur (défaut: 9)}
                            {--force : Exécuter sans vérifier l\'heure locale 9h}
                            {--date= : Date de référence pour le test (format YYYY-MM-DD)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envoie les notifications de souhaits d\'anniversaire aux utilisateurs et à leurs abonnés réciproques';

    /**
     * Execute the console command.
     */
    public function handle(BirthdayNotificationService $birthdayService): int
    {
        $targetHour = $this->option('hour') !== null ? (int) $this->option('hour') : 9;
        $force = (bool) $this->option('force');
        $rawDate = $this->option('date');

        $referenceDate = null;
        if (! empty($rawDate)) {
            $referenceDate = Carbon::parse($rawDate);
        }

        $this->info("Traitement des notifications d'anniversaire (heure cible: {$targetHour}h, force: ".($force ? 'oui' : 'non').')...');

        $result = $birthdayService->notifyBirthdays($referenceDate, $targetHour, $force);

        $this->info("Terminé : {$result['self_sent']} notification(s) personnelle(s) envoyée(s), {$result['friends_sent']} notification(s) amie(s) envoyée(s), {$result['skipped']} ignoré(s).");

        return self::SUCCESS;
    }
}
