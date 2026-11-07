<?php

namespace App\Console\Commands\Media;

use App\Models\Message\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MigratePrivateVoiceMessagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:migrate-private-voices
                            {--dry-run : Analyse et affiche les fichiers à migrer sans les déplacer}
                            {--force : Force la migration même si le fichier existe déjà sur la destination}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migre les messages vocaux et pièces jointes privées du disque public vers le disque privé sécurisé';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $publicDisk = config('media.disk', 'public');
        $privateDisk = config('media.private_disk', 'local');
        $isDryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info('=== Migration des pièces jointes et messages vocaux privés ===');
        $this->info("Disque source (public) : {$publicDisk}");
        $this->info("Disque cible (privé)  : {$privateDisk}");

        if ($isDryRun) {
            $this->warn('Mode Simulation (--dry-run) actif : aucune modification de fichier ne sera appliquée.');
        }

        $query = Message::query()
            ->whereNotNull('attachment_path')
            ->where('attachment_path', '!=', '')
            ->where('is_retracted', false);

        $totalCount = $query->count();
        $this->line("Nombre de messages avec pièces jointes identifiés : <info>{$totalCount}</info>");

        $migratedCount = 0;
        $alreadyPrivateCount = 0;
        $missingCount = 0;
        $errorsCount = 0;

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        $query->chunk(100, function ($messages) use (
            $publicDisk,
            $privateDisk,
            $isDryRun,
            $force,
            &$migratedCount,
            &$alreadyPrivateCount,
            &$missingCount,
            &$errorsCount,
            $bar
        ): void {
            /** @var Message $message */
            foreach ($messages as $message) {
                $path = $message->attachment_path;

                // Ignorer les URLs externes directes
                if (filter_var($path, FILTER_VALIDATE_URL)) {
                    $bar->advance();

                    continue;
                }

                $existsOnPublic = Storage::disk($publicDisk)->exists($path);
                $existsOnPrivate = Storage::disk($privateDisk)->exists($path);

                if ($existsOnPublic) {
                    if ($existsOnPrivate && ! $force) {
                        // Déjà présent sur le disque privé, supprimer l'exposition publique
                        if (! $isDryRun) {
                            Storage::disk($publicDisk)->delete($path);
                        }
                        $alreadyPrivateCount++;
                    } else {
                        try {
                            if (! $isDryRun) {
                                // Copie du flux vers le stockage privé
                                $stream = Storage::disk($publicDisk)->readStream($path);
                                if ($stream !== false) {
                                    Storage::disk($privateDisk)->put($path, $stream);
                                    if (is_resource($stream)) {
                                        fclose($stream);
                                    }
                                }

                                // Vérification du transfert avant suppression de la source publique
                                if (Storage::disk($privateDisk)->exists($path)) {
                                    Storage::disk($publicDisk)->delete($path);
                                    $migratedCount++;
                                } else {
                                    $errorsCount++;
                                    Log::error("Échec de vérification du fichier privé après copie : {$path}");
                                }
                            } else {
                                $migratedCount++;
                            }
                        } catch (\Throwable $e) {
                            $errorsCount++;
                            Log::error("Erreur lors de la migration du fichier {$path} : {$e->getMessage()}");
                        }
                    }
                } elseif ($existsOnPrivate) {
                    $alreadyPrivateCount++;
                } else {
                    $missingCount++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Statut', 'Quantité'],
            [
                ['Fichiers migrés vers le stockage privé', $migratedCount],
                ['Fichiers déjà sécurisés sur le stockage privé', $alreadyPrivateCount],
                ['Fichiers introuvables (fichiers orphelins)', $missingCount],
                ['Erreurs survenues', $errorsCount],
            ]
        );

        if ($migratedCount > 0 && ! $isDryRun) {
            $this->info("✓ Migration terminée avec succès : {$migratedCount} fichier(s) déplacé(s) vers le stockage privé.");
        } elseif ($isDryRun) {
            $this->info("✓ Simulation terminée : {$migratedCount} fichier(s) seraient migrés vers le stockage privé.");
        } else {
            $this->info('✓ Tous les fichiers vocaux et pièces jointes sont déjà protégés sur le stockage privé.');
        }

        return $errorsCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
