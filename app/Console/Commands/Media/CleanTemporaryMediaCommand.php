<?php

namespace App\Console\Commands\Media;

use App\Services\Media\MediaStorageService;
use Illuminate\Console\Command;

class CleanTemporaryMediaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:clean-temp {--hours=24 : Supprimer les fichiers temporaires plus vieux que N heures} {--disk= : Disque cible (défaut: disque configuré)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nettoie de façon sécurisée les fichiers temporaires expirés des répertoires reels/temp, sounds/temp et square/temp';

    /**
     * Execute the console command.
     */
    public function handle(MediaStorageService $service): int
    {
        $hours = (int) $this->option('hours');
        $disk = $this->option('disk') ?: $service->getDisk();

        $this->info("Nettoyage des fichiers temporaires plus vieux que {$hours}h sur le disque [{$disk}]...");

        $result = $service->cleanTemporaryFiles($hours, $disk);

        $freedKb = round($result['freed_bytes'] / 1024, 2);
        $this->info("✓ Nettoyage terminé : {$result['deleted_count']} fichier(s) supprimé(s), {$freedKb} Ko libéré(s).");

        return self::SUCCESS;
    }
}
