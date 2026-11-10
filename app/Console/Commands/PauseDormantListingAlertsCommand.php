<?php

namespace App\Console\Commands;

use App\Models\Listing\ListingAlert;
use App\Notifications\Social\SocialNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:pause-dormant')]
#[Description('Met en pause automatiquement les alertes Immo sans correspondance depuis 90 jours et notifie l\'utilisateur.')]
class PauseDormantListingAlertsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(90);

        $dormantAlerts = ListingAlert::query()
            ->where('is_active', true)
            ->where(function ($query) use ($cutoff) {
                $query->whereNotNull('last_matched_at')->where('last_matched_at', '<=', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('last_matched_at')
                            ->where('created_at', '<=', $cutoff);
                    });
            })
            ->with('user')
            ->get();

        $count = 0;
        foreach ($dormantAlerts as $alert) {
            $alert->update([
                'is_active' => false,
                'paused_reason' => 'no_match_90d',
                'disabled_at' => now(),
            ]);

            $user = $alert->user;
            if ($user) {
                try {
                    $user->notify(new SocialNotification('alert_paused', $user, [
                        'alert_id' => (string) $alert->id,
                        'alert_name' => $alert->name ?: 'Alerte immobilière',
                        'reason' => 'no_match_90d',
                        'message' => 'Ton alerte immobilière a été mise en pause car aucune annonce n\'y a correspondu depuis 90 jours. Tu peux la réactiver à tout moment.',
                    ]));
                } catch (\Throwable $e) {
                    // Ignorer l'échec de notification
                }
            }

            $count++;
        }

        $this->info("{$count} alerte(s) mise(s) en pause.");

        return Command::SUCCESS;
    }
}
