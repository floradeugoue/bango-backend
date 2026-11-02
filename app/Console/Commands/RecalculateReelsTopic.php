<?php

namespace App\Console\Commands;

use App\Models\Reel\Reel;
use App\Services\Reel\ReelTopicResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reels:recalculate-topics')]
#[Description('Recalcule le topic des reels existants d\'après leur rattachement immo, leur légende ou le premier centre d\'intérêt de l\'auteur.')]
class RecalculateReelsTopic extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReelTopicResolver $resolver): int
    {
        $this->info('Début du recalcul des topics des Reels...');

        $reels = Reel::with(['user.interests'])->get();
        $total = $reels->count();

        if ($total === 0) {
            $this->info('Aucun Reel trouvé en base de données.');

            return self::SUCCESS;
        }

        $updatedCount = 0;

        foreach ($reels as $reel) {
            $newTopic = $resolver->resolve(
                caption: $reel->caption,
                listingId: $reel->listing_id,
                user: $reel->user
            );

            if ($reel->topic !== $newTopic) {
                $reel->topic = $newTopic;
                $reel->save();
                $updatedCount++;
            }
        }

        $this->info("Recalcul terminé : {$updatedCount} sur {$total} Reels mis à jour.");

        return self::SUCCESS;
    }
}
