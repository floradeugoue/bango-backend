<?php

namespace App\Console\Commands\Media;

use App\Services\Media\MediaStorageService;
use Illuminate\Console\Command;

class AuditStorageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:audit {--disk= : Disque de stockage à auditer (défaut: disque configuré)} {--ensure-dirs : Créer automatiquement les dossiers manquants}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audite l\'organisation des médias stockés dans storage/app/public et détecte les fichiers orphelins';

    /**
     * Execute the console command.
     */
    public function handle(MediaStorageService $service): int
    {
        $disk = $this->option('disk') ?: $service->getDisk();

        $this->info("=== Audit du stockage BANGO [Disque: {$disk}] ===");

        if ($this->option('ensure-dirs')) {
            $service->ensureDirectoriesExist($disk);
            $this->info('✓ Arborescence des répertoires vérifiée et initialisée.');
        }

        $audit = $service->auditStorage($disk);

        $this->table(
            ['Sous-dossier', 'Nombre de fichiers'],
            collect($audit['directories'])->map(fn ($count, $dir) => [$dir, $count])->all()
        );

        $this->line("Total des fichiers médias : <info>{$audit['total_files']}</info>");

        if (! empty($audit['orphan_files'])) {
            $this->warn(count($audit['orphan_files']).' fichier(s) orphelin(s) détecté(s) (non référencés en base) :');
            foreach ($audit['orphan_files'] as $orphan) {
                $this->line(" - {$orphan}");
            }
            $this->comment('Note : ces fichiers ne sont pas supprimés automatiquement pour préserver l\'intégrité des données.');
        } else {
            $this->info('✓ Aucun fichier orphelin détecté.');
        }

        return self::SUCCESS;
    }
}
