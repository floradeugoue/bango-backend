<?php

namespace App\Console\Commands;

use App\Enums\Story\StoryStatus;
use App\Models\Story\Story;
use Illuminate\Console\Command;

class ProcessStoryExpirationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stories:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Traite l\'expiration automatique des Stories après 24 heures et bascule vers l\'archivage si autorisé.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Vérification des Stories expirées...');

        $now = now();
        $expiredStories = Story::where('status', StoryStatus::Active->value)
            ->where('expires_at', '<=', $now)
            ->get();

        $count = $expiredStories->count();
        $archivedCount = 0;
        $expiredOnlyCount = 0;

        foreach ($expiredStories as $story) {
            if ($story->allow_archive) {
                $story->update(['status' => StoryStatus::Archived]);
                $archivedCount++;
            } else {
                $story->update(['status' => StoryStatus::Expired]);
                $expiredOnlyCount++;
            }
        }

        $this->info("Traitement terminé : {$count} stories traitées ({$archivedCount} archivées, {$expiredOnlyCount} expirées sans archive).");

        return self::SUCCESS;
    }
}
