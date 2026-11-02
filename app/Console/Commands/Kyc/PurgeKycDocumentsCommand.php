<?php

namespace App\Console\Commands\Kyc;

use App\Enums\Kyc\KycStatus;
use App\Enums\Page\PageCertificationStatus;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycSubmission;
use App\Models\Page\PageCertification;
use App\Models\Subscription\Subscription;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeKycDocumentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kyc:purge-documents {--dry-run : Afficher les fichiers qui seraient détruits sans les supprimer}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Détruit les pièces KYC rejetées depuis plus de 30 jours, les pièces de comptes sans abonnement actif depuis 90 jours, et les certifications de page rejetées depuis 30 jours.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('Démarrage de la purge de conformité des pièces sensibles...');

        $deletedKycDocsCount = 0;
        $deletedPageDocsCount = 0;

        // 1. Dossiers KYC rejetés depuis plus de 30 jours
        $cutoffRejectedKyc = Carbon::now()->subDays(30);
        $rejectedSubmissions = KycSubmission::where('status', KycStatus::Rejected)
            ->where(function ($query) use ($cutoffRejectedKyc) {
                $query->where('decided_at', '<=', $cutoffRejectedKyc)
                    ->orWhere(function ($q) use ($cutoffRejectedKyc) {
                        $q->whereNull('decided_at')
                            ->where('updated_at', '<=', $cutoffRejectedKyc);
                    });
            })
            ->with('documents')
            ->get();

        foreach ($rejectedSubmissions as $submission) {
            foreach ($submission->documents as $doc) {
                if ($doc->file_path && Storage::disk('local')->exists($doc->file_path)) {
                    if (! $dryRun) {
                        Storage::disk('local')->delete($doc->file_path);
                    }
                    $deletedKycDocsCount++;
                }

                if (! $dryRun) {
                    $doc->update([
                        'file_path' => 'purged',
                        'status' => 'purged',
                        'rejection_reason' => ($doc->rejection_reason ? $doc->rejection_reason.' | ' : '').'Détruit 30 jours après refus.',
                    ]);
                }
            }
        }

        // 2. Utilisateurs sans abonnement actif depuis plus de 90 jours (destruction des pièces KYC 90 jours après fin du dernier abonnement)
        // Attention : Ne pas détruire si l'utilisateur a un abonnement actif en cours, ou s'il n'a pas encore atteint les 90 jours depuis la fin de son dernier abonnement.
        $cutoffSubscriptionEnded = Carbon::now()->subDays(90);

        // Utilisateurs ayant au moins une souscription passée, mais AUCUNE active, et dont la fin du dernier abonnement remonte à plus de 90 jours
        $usersWithExpiredSubscriptions = User::whereHas('subscriptions')
            ->whereDoesntHave('subscriptions', function ($q) {
                // Tout abonnement actif, trialing ou en période de grâce
                $q->whereIn('state', ['active', 'trialing', 'grace'])
                    ->where(function ($subQ) {
                        $subQ->whereNull('current_period_end')
                            ->orWhere('current_period_end', '>', now());
                    });
            })
            ->get();

        foreach ($usersWithExpiredSubscriptions as $user) {
            /** @var Subscription|null $latestSub */
            $latestSub = $user->subscriptions()->orderByDesc('current_period_end')->first();

            // Vérifier que le dernier abonnement est bien terminé depuis au moins 90 jours
            $lastEnd = $latestSub?->current_period_end ?? $latestSub?->updated_at;
            if ($lastEnd && $lastEnd->lte($cutoffSubscriptionEnded)) {
                $userDocs = KycDocument::where('user_id', $user->id)
                    ->whereNotNull('file_path')
                    ->where('file_path', '!=', 'purged')
                    ->get();

                foreach ($userDocs as $doc) {
                    if (Storage::disk('local')->exists($doc->file_path)) {
                        if (! $dryRun) {
                            Storage::disk('local')->delete($doc->file_path);
                        }
                        $deletedKycDocsCount++;
                    }

                    if (! $dryRun) {
                        $doc->update([
                            'file_path' => 'purged',
                            'status' => 'purged',
                            'rejection_reason' => ($doc->rejection_reason ? $doc->rejection_reason.' | ' : '').'Détruit 90 jours après la fin du dernier abonnement.',
                        ]);
                    }
                }
            }
        }

        // 3. Certifications de page rejetées depuis plus de 30 jours
        $cutoffRejectedPageCert = Carbon::now()->subDays(30);
        $rejectedPageCerts = PageCertification::where('status', PageCertificationStatus::Rejected)
            ->where(function ($query) use ($cutoffRejectedPageCert) {
                $query->where('decided_at', '<=', $cutoffRejectedPageCert)
                    ->orWhere(function ($q) use ($cutoffRejectedPageCert) {
                        $q->whereNull('decided_at')
                            ->where('updated_at', '<=', $cutoffRejectedPageCert);
                    });
            })
            ->get();

        foreach ($rejectedPageCerts as $pageCert) {
            $docs = $pageCert->documents ?? [];
            $modified = false;

            foreach ($docs as $key => $docMeta) {
                $filePath = $docMeta['file_path'] ?? null;
                if ($filePath && Storage::disk('local')->exists($filePath)) {
                    if (! $dryRun) {
                        Storage::disk('local')->delete($filePath);
                    }
                    $deletedPageDocsCount++;
                }

                if (isset($docs[$key]['file_path'])) {
                    $docs[$key]['file_path'] = null;
                    $docs[$key]['status'] = 'purged';
                    $docs[$key]['purged_at'] = now()->toIso8601String();
                    $modified = true;
                }
            }

            if ($modified && ! $dryRun) {
                $pageCert->documents = $docs;
                $pageCert->save();
            }
        }

        $summary = sprintf(
            'Purge terminée : %d pièces KYC détruites, %d pièces de certification de page détruites%s.',
            $deletedKycDocsCount,
            $deletedPageDocsCount,
            $dryRun ? ' (MODE DRY-RUN)' : ''
        );

        $this->info($summary);
        Log::info("PurgeKycDocumentsCommand: {$summary}");

        return Command::SUCCESS;
    }
}
