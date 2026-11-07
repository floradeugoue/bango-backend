<?php

namespace App\Console\Commands\Media;

use App\Enums\Media\MediaProcessingStatus;
use App\Models\Square\PostMedia;
use App\Services\Media\MediaProcessingService;
use Illuminate\Console\Command;

class RetryFailedMediaProcessingCommand extends Command
{
    protected $signature = 'media:retry-failed {--id= : ID du PostMedia à relancer} {--all : Relancer tous les médias échoués}';

    protected $description = 'Relance le traitement asynchrone des médias vidéo échoués';

    public function handle(MediaProcessingService $service): int
    {
        $id = $this->option('id');
        $all = $this->option('all');

        if (! $id && ! $all) {
            $this->error('Spécifiez --id=<id> ou --all pour relancer les traitements échoués.');

            return Command::FAILURE;
        }

        if ($id) {
            $media = PostMedia::find($id);
            if (! $media) {
                $this->error("PostMedia #{$id} introuvable.");

                return Command::FAILURE;
            }

            try {
                $service->retryPostMedia($media);
                $this->info("PostMedia #{$id} reprogrammé avec succès.");

                return Command::SUCCESS;
            } catch (\Throwable $e) {
                $this->error("Erreur: {$e->getMessage()}");

                return Command::FAILURE;
            }
        }

        $failedMedias = PostMedia::where('status', MediaProcessingStatus::Failed->value)->get();
        if ($failedMedias->isEmpty()) {
            $this->info('Aucun média en échec à relancer.');

            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($failedMedias as $media) {
            $service->retryPostMedia($media);
            $count++;
        }

        $this->info("{$count} média(s) en échec reprogrammé(s) avec succès.");

        return Command::SUCCESS;
    }
}
