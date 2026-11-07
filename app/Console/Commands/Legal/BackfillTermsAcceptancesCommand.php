<?php

namespace App\Console\Commands\Legal;

use App\Models\Legal\TermsAcceptance;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillTermsAcceptancesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'terms:backfill 
                            {--terms-version= : Version des CGU a attribuer (par defaut: version obligatoire courante)}
                            {--dry-run : Simuler sans modifier la base de donnees}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rattrape l\'acceptation des CGU pour les comptes crees avant le deploiement du controle';

    /**
     * Execute the console command.
     */
    public function handle(LegalDocumentService $legalService): int
    {
        $version = $this->option('terms-version') ?: $legalService->getMandatoryTermsVersion();
        $isDryRun = (bool) $this->option('dry-run');

        $this->info("=== RATTRAPAGE DES ACCEPTATIONS CGU (Version : {$version}) ===");

        $users = User::query()
            ->where(function ($query) use ($version) {
                $query->whereNull('accepted_terms_version')
                    ->orWhere('accepted_terms_version', '!=', $version)
                    ->orWhereNull('terms_accepted_at');
            })
            ->get();

        $count = $users->count();

        if ($count === 0) {
            $this->info('Tous les comptes existants ont deja accepte la version requise des CGU.');

            return self::SUCCESS;
        }

        $this->line("Nombre de comptes sans acceptation formelle de la v{$version} : <comment>{$count}</comment>");

        if ($isDryRun) {
            $this->warn('Mode DRY-RUN actif : aucune modification n\'a ete appliquee.');

            return self::SUCCESS;
        }

        $updated = 0;

        DB::transaction(function () use ($users, $version, &$updated) {
            foreach ($users as $user) {
                $acceptedAt = $user->created_at ?? now();

                // 1. Enregistrement d'audit dans terms_acceptances s'il n'existe pas deja
                TermsAcceptance::firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'document_slug' => LegalDocumentService::TERMS_SLUG,
                        'version' => $version,
                    ],
                    [
                        'locale' => 'fr',
                        'ip_address' => '127.0.0.1',
                        'user_agent' => 'Migration / Backfill Script',
                        'accepted_at' => $acceptedAt,
                    ]
                );

                // 2. Synchronisation sur l'utilisateur
                $user->update([
                    'accepted_terms_version' => $version,
                    'terms_accepted_at' => $user->terms_accepted_at ?? $acceptedAt,
                ]);

                $updated++;
            }
        });

        $this->info("Succes : {$updated} comptes ont ete synchronises avec l'acceptation des CGU v{$version}.");

        return self::SUCCESS;
    }
}
