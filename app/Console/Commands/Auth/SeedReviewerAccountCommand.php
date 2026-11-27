<?php

namespace App\Console\Commands\Auth;

use Database\Seeders\ReviewerAccountSeeder;
use Illuminate\Console\Command;

class SeedReviewerAccountCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:seed-reviewer-account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Génère ou met à jour le compte de démonstration pour les vérificateurs Apple et Google';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Génération du compte de démonstration pour vérificateurs...');

        $seeder = new ReviewerAccountSeeder;
        $seeder->run();

        $this->info('Compte de démonstration vérificateur généré avec succès !');
        $this->line('  Email: reviewer@bangoapp.com');
        $this->line('  Mot de passe: BangoReview2026!');
        $this->line('  Nom: Kouamé N\'Dri (@kouame_bango)');

        return self::SUCCESS;
    }
}
