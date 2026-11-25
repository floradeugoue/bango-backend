<?php

namespace App\Console\Commands;

use App\Services\Support\DemoAccountCleanupService;
use Illuminate\Console\Command;

class PurgeDemoAccountsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:purge {--force : Forcer l\'exécution sans confirmation interactive}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge définitivement les 8 comptes de démonstration (Samuel Moukoko, etc.) et tout leur contenu associé';

    /**
     * Execute the console command.
     */
    public function handle(DemoAccountCleanupService $service): int
    {
        $demoUsers = $service->findDemoUsers();

        if ($demoUsers->isEmpty()) {
            $this->info('Aucun compte de démonstration trouvé en base de données.');

            return self::SUCCESS;
        }

        $this->warn('Comptes de démonstration détectés :');
        foreach ($demoUsers as $user) {
            $this->line("- [ID {$user->id}] {$user->name} (@{$user->handle}) <{$user->email}>");
        }

        if (! $this->option('force') && ! $this->confirm('Voulez-vous supprimer définitivement ces comptes et tous leurs contenus ?', true)) {
            $this->info('Opération annulée.');

            return self::SUCCESS;
        }

        $result = $service->purge();

        $this->info('Purge terminée avec succès :');
        $this->line("- {$result['users_deleted']} compte(s) supprimé(s)");
        $this->line("- {$result['posts_deleted']} publication(s) supprimée(s)");
        $this->line("- {$result['listings_deleted']} annonce(s) supprimée(s)");
        $this->line("- {$result['stories_deleted']} story/stories supprimée(s)");
        $this->line("- {$result['pages_deleted']} page(s) supprimée(s)");

        return self::SUCCESS;
    }
}
