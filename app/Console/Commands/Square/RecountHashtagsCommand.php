<?php

namespace App\Console\Commands\Square;

use App\Models\Square\Hashtag;
use Illuminate\Console\Command;

class RecountHashtagsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hashtags:recount';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalcule le nombre réel de publications visibles et actives pour chaque hashtag.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hashtags = Hashtag::all();

        $this->info("Recalcul de {$hashtags->count()} hashtags...");

        $updated = 0;
        foreach ($hashtags as $hashtag) {
            $oldCount = $hashtag->posts_count;
            $newCount = $hashtag->recalculatePostsCount();

            if ($oldCount !== $newCount) {
                $updated++;
            }
        }

        $this->info("Recalcul terminé : {$updated} hashtags mis à jour.");

        return Command::SUCCESS;
    }
}

