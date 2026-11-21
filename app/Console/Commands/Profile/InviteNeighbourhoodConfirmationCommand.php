<?php

namespace App\Console\Commands\Profile;

use App\Services\Profile\NeighbourhoodConfirmationInvitationService;
use Illuminate\Console\Command;

class InviteNeighbourhoodConfirmationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'profile:invite-neighbourhood-confirmation
                            {--dry-run : Simuler sans envoyer de notifications}
                            {--force : Réinviter même les comptes déjà notifiés}
                            {--limit=0 : Nombre maximal de comptes à traiter (0 = illimité)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Invite les comptes existants dont le quartier n\'est pas encore vérifié à le confirmer par leur position';

    /**
     * Execute the console command.
     */
    public function handle(NeighbourhoodConfirmationInvitationService $invitationService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));

        $this->info('Recherche des comptes existants sans quartier vérifié...');

        $stats = $invitationService->inviteUnverifiedAccounts(
            dryRun: $dryRun,
            force: $force,
            limit: $limit
        );

        $modeLabel = $dryRun ? ' [DRY-RUN]' : '';
        $this->info(
            "Terminé{$modeLabel} — Analysés : {$stats['checked']} | Invités : {$stats['sent']} | Déjà invités (ignorés) : {$stats['skipped']}"
        );

        return self::SUCCESS;
    }
}
