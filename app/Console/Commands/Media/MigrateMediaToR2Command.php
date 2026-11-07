<?php

namespace App\Console\Commands\Media;

use App\Services\Media\MediaStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MigrateMediaToR2Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:migrate-to-r2
                            {--type=public : Type de médias à migrer : "public" (disque public vers r2) ou "private" (disque local vers r2_private)}
                            {--source-disk= : Nom du disque source personnalisé (optionnel)}
                            {--target-disk= : Nom du disque cible personnalisé (optionnel)}
                            {--dry-run : Simuler la migration sans modifier ni copier aucun fichier}
                            {--verify-only : Vérifier uniquement l\'intégrité des fichiers déjà transférés sur R2}
                            {--delete-source : Supprimer les fichiers sources locaux UNIQUEMENT après validation stricte d\'intégrité sur R2}
                            {--limit=0 : Nombre maximal de fichiers à traiter (0 = tous)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migre de manière sécurisée, progressive et avec reprise après interruption les médias locaux vers Cloudflare R2';

    /**
     * Execute the console command.
     */
    public function handle(MediaStorageService $service): int
    {
        $type = strtolower($this->option('type') ?: 'public');
        $isPrivate = ($type === 'private');

        $sourceDisk = $this->option('source-disk') ?: ($isPrivate ? config('media.private_disk', 'local') : config('media.disk', 'public'));
        $targetDisk = $this->option('target-disk') ?: ($isPrivate ? 'r2_private' : 'r2');

        $isDryRun = (bool) $this->option('dry-run');
        $isVerifyOnly = (bool) $this->option('verify-only');
        $deleteSource = (bool) $this->option('delete-source');
        $limit = (int) $this->option('limit');

        $this->info("=== Migration des Médias vers Cloudflare R2 [{$type}] ===");
        $this->info("Disque source : {$sourceDisk}");
        $this->info("Disque cible  : {$targetDisk}");

        if ($isDryRun) {
            $this->warn('Mode Simulation actif (--dry-run) : aucun fichier ne sera copié ni supprimé.');
        } elseif ($isVerifyOnly) {
            $this->warn('Mode Vérification d\'intégrité (--verify-only) : analyse de concordance sans copie.');
        } elseif ($deleteSource) {
            $this->alert('ATTENTION : L\'option --delete-source est activée. Les fichiers locaux validés sur R2 seront supprimés après vérification d\'intégrité.');
        }

        // 1. Test de connectivité préalable avec R2
        try {
            $targetStorage = Storage::disk($targetDisk);
            // Vérification basique de connectivité
            $targetStorage->exists('.healthcheck');
        } catch (\Throwable $e) {
            $this->error("Erreur de connexion au disque cible R2 [{$targetDisk}] : ".$e->getMessage());
            $this->line('Assurez-vous que les variables CLOUDFLARE_R2_* sont correctement configurées dans le fichier .env.');

            return self::FAILURE;
        }

        $sourceStorage = Storage::disk($sourceDisk);

        $directories = $isPrivate ? [
            'messages/voices',
            'messages/attachments',
            'kyc/documents',
        ] : [
            'square/images',
            'square/videos',
            'square/renditions',
            'reels/videos',
            'reels/covers',
            'reels/thumbnails',
            'reels/renditions',
            'sounds/catalogue',
            'sounds/originals',
            'stories/images',
            'stories/videos',
            'stories/thumbnails',
            'listings/images',
            'listings/videos',
            'listings/thumbnails',
            'avatars',
        ];

        $allSourceFiles = [];
        foreach ($directories as $dir) {
            if ($sourceStorage->exists($dir)) {
                $files = $sourceStorage->allFiles($dir);
                foreach ($files as $file) {
                    if (! str_ends_with($file, '.gitignore') && ! str_contains($file, '/temp/')) {
                        $allSourceFiles[] = $file;
                    }
                }
            }
        }

        $totalFiles = count($allSourceFiles);
        $this->line("Nombre total de fichiers sources identifiés : <info>{$totalFiles}</info>");

        if ($totalFiles === 0) {
            $this->info('Aucun fichier à migrer pour ce profil.');

            return self::SUCCESS;
        }

        if ($limit > 0 && $limit < $totalFiles) {
            $allSourceFiles = array_slice($allSourceFiles, 0, $limit);
            $this->warn("Traitement limité aux {$limit} premiers fichiers.");
        }

        $transferredCount = 0;
        $alreadyOnTargetCount = 0;
        $integrityVerifiedCount = 0;
        $integrityFailedCount = 0;
        $deletedSourceCount = 0;
        $errorCount = 0;
        $bytesTransferred = 0;

        $bar = $this->output->createProgressBar(count($allSourceFiles));
        $bar->start();

        foreach ($allSourceFiles as $path) {
            try {
                $sourceSize = $sourceStorage->size($path);
                $existsOnTarget = $targetStorage->exists($path);

                if ($existsOnTarget) {
                    $targetSize = $targetStorage->size($path);

                    // Vérification de concordance de taille (intégrité)
                    if ($targetSize === $sourceSize) {
                        $alreadyOnTargetCount++;
                        $integrityVerifiedCount++;

                        // Si --delete-source demandé et intégrité validée
                        if ($deleteSource && ! $isDryRun && ! $isVerifyOnly) {
                            $sourceStorage->delete($path);
                            $deletedSourceCount++;
                        }

                        $bar->advance();

                        continue;
                    } else {
                        // Fichier corrompu ou incomplet sur R2 : re-transfert nécessaire
                        Log::warning("Fichier R2 existant mais taille divergente pour {$path} (Source: {$sourceSize}, R2: {$targetSize}). Re-transfert planifié.");
                    }
                }

                if ($isVerifyOnly) {
                    $integrityFailedCount++;
                    $bar->advance();

                    continue;
                }

                if ($isDryRun) {
                    $transferredCount++;
                    $bytesTransferred += $sourceSize;
                    $bar->advance();

                    continue;
                }

                // Transfert par flux de lecture (stream) pour gérer les fichiers volumineux sans saturer la mémoire
                $readStream = $sourceStorage->readStream($path);
                if ($readStream === false) {
                    $errorCount++;
                    Log::error("Impossible d'ouvrir le flux source pour {$path}");
                    $bar->advance();

                    continue;
                }

                $uploaded = $targetStorage->put($path, $readStream);
                if (is_resource($readStream)) {
                    fclose($readStream);
                }

                if (! $uploaded) {
                    $errorCount++;
                    Log::error("Échec du téléversement vers R2 pour {$path}");
                    $bar->advance();

                    continue;
                }

                // Vérification post-transfert d'intégrité
                if ($targetStorage->exists($path)) {
                    $newTargetSize = $targetStorage->size($path);
                    if ($newTargetSize === $sourceSize) {
                        $transferredCount++;
                        $integrityVerifiedCount++;
                        $bytesTransferred += $sourceSize;

                        if ($deleteSource) {
                            $sourceStorage->delete($path);
                            $deletedSourceCount++;
                        }
                    } else {
                        $integrityFailedCount++;
                        Log::error("Échec d'intégrité après upload R2 pour {$path} (Source: {$sourceSize}, Cible: {$newTargetSize})");
                    }
                } else {
                    $errorCount++;
                    Log::error("Fichier introuvable sur R2 après upload pour {$path}");
                }

            } catch (\Throwable $e) {
                $errorCount++;
                Log::error("Exception migration R2 pour {$path} : ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Fichiers sources analysés', count($allSourceFiles)],
                ['Fichiers déjà présents et intègres sur R2 (reprise)', $alreadyOnTargetCount],
                ['Nouveaux fichiers transférés vers R2', $transferredCount],
                ['Intégrités validées avec succès', $integrityVerifiedCount],
                ['Échecs d\'intégrité détectés', $integrityFailedCount],
                ['Fichiers sources locaux supprimés (--delete-source)', $deletedSourceCount],
                ['Erreurs survenues', $errorCount],
                ['Volume transféré', round($bytesTransferred / (1024 * 1024), 2).' Mo'],
            ]
        );

        if ($errorCount > 0 || $integrityFailedCount > 0) {
            $this->warn("La migration a rencontré {$errorCount} erreur(s) et {$integrityFailedCount} échec(s) d'intégrité. Consultez les logs.");

            return self::FAILURE;
        }

        $this->info('✓ Opération de migration vers R2 terminée avec succès.');

        return self::SUCCESS;
    }
}
