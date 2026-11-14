<?php

namespace App\Console\Commands\Admin;

use App\Enums\Listing\ListingStatus;
use App\Enums\Page\PageStatus;
use App\Enums\Reel\ReelStatus;
use App\Enums\Support\ReportStatus;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Support\ModerationDecision;
use App\Models\Support\Report;
use App\Notifications\Support\ModerationDecisionNotification;
use App\Services\Admin\ModerationQueueService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnforceExpiredModerationCorrectionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'moderation:enforce-expired-corrections';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Masque automatiquement les contenus dont le délai de correction en modération a expiré sans mise en conformité';

    /**
     * Execute the console command.
     */
    public function handle(ModerationQueueService $queueService): int
    {
        $now = now();
        $this->info("Vérification des demandes de correction de modération expirées à {$now->toDateTimeString()}...");

        // Recherche des signalements dont l'action_taken est request_correction et toujours ouverts
        $reports = Report::query()
            ->where('action_taken', 'request_correction')
            ->whereIn('status', [ReportStatus::Investigating->value, ReportStatus::Pending->value])
            ->get();

        $enforcedCount = 0;

        foreach ($reports as $report) {
            $metadata = is_array($report->metadata) ? $report->metadata : [];
            $deadlineStr = $metadata['correction_deadline'] ?? null;

            if (! $deadlineStr) {
                continue;
            }

            try {
                $deadline = Carbon::parse($deadlineStr);
            } catch (\Throwable $e) {
                continue;
            }

            if ($now->lessThan($deadline)) {
                // Délai pas encore expiré
                continue;
            }

            // Le délai a expiré : masquage automatique du contenu
            $targetClass = Report::resolveReportableClass($report->reportable_type);
            $content = ($targetClass && class_exists($targetClass)) ? $targetClass::find($report->reportable_id) : null;

            if ($content) {
                if ($content instanceof Listing) {
                    $content->status = ListingStatus::Draft;
                    $content->withdrawn_by_bango = true;
                    $content->rejection_reason = "Masqué automatiquement suite à l'expiration du délai de correction imparti.";
                    $content->save();
                } elseif ($content instanceof Post) {
                    $content->is_archived = true;
                    $content->save();
                } elseif ($content instanceof Reel) {
                    $content->status = ReelStatus::Draft;
                    $content->save();
                } elseif ($content instanceof Page) {
                    $content->status = PageStatus::Suspended;
                    $content->save();
                } elseif (method_exists($content, 'delete')) {
                    $content->delete();
                }

                // Notifier l'auteur du masquage
                $author = $queueService->resolveContentAuthor($content);
                if ($author) {
                    try {
                        $author->notify(new ModerationDecisionNotification(
                            $report,
                            'hide',
                            'Votre contenu a été masqué automatiquement car le délai de correction a expiré sans mise en conformité.'
                        ));
                    } catch (\Throwable $e) {
                        Log::warning("Échec notification masquage expiration délai correction : {$e->getMessage()}");
                    }
                }
            }

            // Clôture du dossier en Résolu (Action Hide)
            $report->status = ReportStatus::Resolved;
            $report->action_taken = 'hide';
            $report->metadata = array_merge($metadata, [
                'auto_hidden_at' => $now->toIso8601String(),
                'auto_hidden_reason' => 'correction_deadline_expired',
            ]);
            $report->save();

            // Enregistrement d'une décision système traçable
            ModerationDecision::create([
                'report_id' => $report->id,
                'operator_id' => $report->resolved_by ?? 1,
                'action' => 'hide',
                'justification' => "Masquage automatique suite à l'expiration du délai de correction ({$deadlineStr})",
                'metadata' => [
                    'automated' => true,
                    'reason' => 'correction_deadline_expired',
                    'original_action' => 'request_correction',
                ],
                'created_at' => $now,
            ]);

            $enforcedCount++;
            $this->info("Contenu masqué pour le dossier {$report->reference} (échéance : {$deadlineStr}).");
        }

        $this->info("Opération terminée : {$enforcedCount} contenu(s) masqué(s) suite à expiration du délai.");

        return Command::SUCCESS;
    }
}
