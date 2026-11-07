<?php

namespace App\Console\Commands\Media;

use App\Services\Media\MediaProcessingService;
use Illuminate\Console\Command;

class CleanStuckMediaProcessingCommand extends Command
{
    protected $signature = 'media:clean-stuck {--timeout= : Seuil en minutes pour considérer un traitement comme bloqué}';

    protected $description = 'Détecte et marque comme échouées les vidéos bloquées indéfiniment en statut processing';

    public function handle(MediaProcessingService $service): int
    {
        $timeout = (int) ($this->option('timeout') ?: config('media.stuck_job_timeout_minutes', 15));

        $count = $service->cleanStuckProcessingVideos($timeout);

        if ($count === 0) {
            $this->info("Aucune vidéo bloquée en statut 'processing' (seuil: {$timeout} min).");
        } else {
            $this->warn("{$count} vidéo(s) bloquée(s) depuis plus de {$timeout} min passée(s) en 'failed'.");
        }

        return Command::SUCCESS;
    }
}
