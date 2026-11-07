<?php

namespace App\Console\Commands\Media;

use App\Services\Media\MediaStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanOrphanMediaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:clean-orphans
                            {--disk= : Disque de stockage cible (défaut: disque public configuré)}
                            {--hours=24 : Ignorer les fichiers plus récents que N heures (sécurité anti-suppression active)}
                            {--dry-run : Afficher les fichiers orphelins détectés sans les supprimer}
                            {--force : Exécuter réellement la suppression physique des fichiers orphelins confirmés}
                            {--limit=500 : Nombre maximal de fichiers à traiter par exécution}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Détecte et nettoie de façon sécurisée les fichiers médias orphelins non référencés en base de données';

    /**
     * Execute the console command.
     */
    public function handle(MediaStorageService $service): int
    {
        $disk = $this->option('disk') ?: $service->getDisk();
        $hours = (int) $this->option('hours');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run') || ! $force;
        $limit = (int) $this->option('limit');

        $this->info("=== Nettoyage sécurisé des médias orphelins [Disque: {$disk}] ===");
        if ($dryRun) {
            $this->warn('Mode Simulation actif (--dry-run) : aucun fichier ne sera supprimé.');
        } else {
            $this->warn('Mode Réel actif (--force) : les fichiers orphelins confirmés seront définitivement supprimés.');
        }

        $now = time();
        $minAgeSeconds = $hours * 3600;

        $targetDirectories = [
            'square/images',
            'square/videos',
            'reels/videos',
            'reels/covers',
            'reels/thumbnails',
            'sounds/catalogue',
            'sounds/originals',
            'avatars',
            'messages/voices',
        ];

        $totalScanned = 0;
        $referencedCount = 0;
        $recentProtectedCount = 0;
        $orphansCount = 0;
        $freedBytes = 0;
        $orphansList = [];

        foreach ($targetDirectories as $dir) {
            if (! Storage::disk($disk)->exists($dir)) {
                continue;
            }

            $files = Storage::disk($disk)->allFiles($dir);

            foreach ($files as $file) {
                // Ignorer les fichiers techniques (.gitignore, etc.)
                if (str_ends_with($file, '.gitignore') || str_contains($file, '/temp/')) {
                    continue;
                }

                $totalScanned++;

                // 1. Vérification de référence en base de données
                if ($service->isFileReferenced($file)) {
                    $referencedCount++;

                    continue;
                }

                // 2. Vérification de l'âge du fichier (sécurité anti-suppression des uploads récents)
                $lastModified = Storage::disk($disk)->lastModified($file);
                $fileAgeSeconds = $now - $lastModified;

                if ($fileAgeSeconds < $minAgeSeconds) {
                    $recentProtectedCount++;

                    continue;
                }

                // Fichier orphelin confirmé
                $orphansCount++;
                $fileSize = Storage::disk($disk)->size($file);
                $freedBytes += $fileSize;

                if (! $dryRun) {
                    try {
                        Storage::disk($disk)->delete($file);
                        Log::info("Nettoyage média orphelin: [{$disk}] {$file} ({$fileSize} octets)");
                    } catch (\Throwable $e) {
                        Log::error("Erreur suppression orphelin {$file}: {$e->getMessage()}");
                    }
                }

                if (count($orphansList) < 50) {
                    $orphansList[] = [
                        $file,
                        round($fileSize / 1024, 2).' Ko',
                        round($fileAgeSeconds / 3600, 1).' h',
                        $dryRun ? 'À supprimer (simulation)' : 'Supprimé',
                    ];
                }

                if ($orphansCount >= $limit) {
                    $this->warn("Limite de {$limit} fichiers atteinte pour cette exécution.");
                    break 2;
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Fichiers analysés', $totalScanned],
                ['Fichiers référencés en base (conservés)', $referencedCount],
                ["Fichiers récents protégés (< {$hours}h)", $recentProtectedCount],
                [$dryRun ? 'Orphelins détectés (non supprimés)' : 'Orphelins supprimés avec succès', $orphansCount],
                ['Espace disque libéré / libérable', round($freedBytes / 1024, 2).' Ko'],
            ]
        );

        if (! empty($orphansList)) {
            $this->newLine();
            $this->info('Échantillon des fichiers orphelins traités :');
            $this->table(['Chemin', 'Taille', 'Âge', 'Action'], $orphansList);
        }

        if ($dryRun && $orphansCount > 0) {
            $this->info("Pour supprimer réellement ces fichiers orphelins, relancez avec : php artisan media:clean-orphans --force --hours={$hours}");
        }

        return self::SUCCESS;
    }
}
