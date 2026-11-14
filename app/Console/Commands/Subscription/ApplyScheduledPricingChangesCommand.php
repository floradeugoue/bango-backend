<?php

namespace App\Console\Commands\Subscription;

use App\Services\Admin\Settings\AdminSystemSettingsService;
use Illuminate\Console\Command;

class ApplyScheduledPricingChangesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pricing:apply-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Applique les propositions de modifications tarifaires programmées arrivées à échéance (catalogue mis à jour, maintien de l\'ancien tarif des abonnés jusqu\'au renouvellement).';

    /**
     * Execute the console command.
     */
    public function handle(AdminSystemSettingsService $settingsService): int
    {
        $this->info('Vérification et application des changements tarifaires arrivés à échéance...');

        $result = $settingsService->applyDuePricingChanges();

        $this->info("{$result['applied_count']} proposition(s) tarifaire(s) appliquée(s) avec succès.");

        return Command::SUCCESS;
    }
}
