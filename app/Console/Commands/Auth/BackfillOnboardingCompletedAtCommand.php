<?php

namespace App\Console\Commands\Auth;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOnboardingCompletedAtCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:backfill-onboarding
                            {--dry-run : Simuler sans modifier la base de données}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rattrape onboarding_completed_at pour les comptes déjà inscrits ayant date de naissance et CGU acceptées';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $this->info('=== RATTRAPAGE DE ONBOARDING_COMPLETED_AT ===');

        $query = User::query()
            ->whereNotNull('birth_date')
            ->whereNotNull('terms_accepted_at')
            ->whereNull('onboarding_completed_at');

        $count = $query->count();

        if ($count === 0) {
            $this->info('Tous les comptes éligibles ont déjà onboarding_completed_at renseigné.');

            return self::SUCCESS;
        }

        $this->line("Nombre de comptes à rattraper : <comment>{$count}</comment>");

        if ($isDryRun) {
            $this->warn('Mode DRY-RUN actif : aucune modification n\'a été appliquée.');

            return self::SUCCESS;
        }

        $updated = 0;

        DB::transaction(function () use ($query, &$updated): void {
            $users = $query->get();
            foreach ($users as $user) {
                $completedAt = $user->terms_accepted_at ?? $user->created_at ?? now();
                $user->update([
                    'onboarding_completed_at' => $completedAt,
                ]);
                $updated++;
            }
        });

        $this->info("Succès : {$updated} comptes ont été synchronisés avec onboarding_completed_at.");

        return self::SUCCESS;
    }
}
