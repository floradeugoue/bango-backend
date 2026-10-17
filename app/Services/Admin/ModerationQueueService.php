<?php

namespace App\Services\Admin;

use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminAuditLog;
use App\Models\Auth\Role;
use App\Models\Boost\Campaign;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationDecision;
use App\Models\Support\Report;
use App\Models\User;
use App\Services\Boost\CampaignService;
use App\Services\Boost\CreditWalletService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ModerationQueueService
{
    public function __construct(
        protected CampaignService $campaignService,
        protected CreditWalletService $creditWalletService
    ) {}

    /**
     * Récupère la file de signalements paginée avec filtres, onglets et compteurs.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getQueue(array $filters, int $perPage = 20): array
    {
        $query = Report::query()->with(['reporter:id,name,email,avatar', 'assignedOperator:id,name,email']);

        // 1. Filtrage par onglet (tab)
        $tab = $filters['tab'] ?? 'all';
        if ($tab !== 'all') {
            $class = Report::resolveReportableClass($tab);
            if ($class) {
                $query->where('reportable_type', $class);
            }
        }

        // 2. Filtres par motif (reason)
        if (! empty($filters['reason'])) {
            $query->where('reason', $filters['reason']);
        }

        // 3. Filtre par statut du signalement
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            // Par défaut, afficher les dossiers en attente ou en cours
            $query->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Investigating->value]);
        }

        // 4. Filtre par priorité
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        // 5. Filtre par opérateur assigné
        if (isset($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'unassigned') {
                $query->whereNull('assigned_to');
            } elseif (is_numeric($filters['assigned_to'])) {
                $query->where('assigned_to', (int) $filters['assigned_to']);
            }
        }

        // 6. Filtre par dates
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['date_from'])->toDateString());
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['date_to'])->toDateString());
        }

        // 7. Recherche par référence ou contenu
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('reportable_id', 'LIKE', "%{$search}%")
                    ->orWhere('details', 'LIKE', "%{$search}%");
            });
        }

        // 8. Tri contrôlé (liste blanche stricte)
        $allowedSorts = ['created_at', 'priority', 'status', 'reference', 'id'];
        $sortBy = in_array($filters['sort_by'] ?? '', $allowedSorts, true) ? $filters['sort_by'] : 'created_at';
        $sortDir = strtolower($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginated = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        // 9. Calcul des compteurs KPI de la file
        $counters = [
            'total_pending' => Report::where('status', ReportStatus::Pending->value)->count(),
            'total_investigating' => Report::where('status', ReportStatus::Investigating->value)->count(),
            'urgent_count' => Report::whereIn('status', [ReportStatus::Pending->value, ReportStatus::Investigating->value])->where('priority', 'urgent')->count(),
            'unassigned_count' => Report::whereIn('status', [ReportStatus::Pending->value, ReportStatus::Investigating->value])->whereNull('assigned_to')->count(),
            'all' => Report::count(),
            'posts' => Report::where('reportable_type', 'post')->orWhere('reportable_type', 'App\\Models\\Square\\Post')->count(),
            'reels' => Report::where('reportable_type', 'reel')->orWhere('reportable_type', 'App\\Models\\Reel\\Reel')->count(),
            'listings' => Report::where('reportable_type', 'listing')->orWhere('reportable_type', 'App\\Models\\Listing\\Listing')->count(),
            'messages' => Report::where('reportable_type', 'message')->orWhere('reportable_type', 'App\\Models\\Message\\Message')->count(),
            'pending' => Report::where('status', ReportStatus::Pending->value)->count(),
        ];

        return [
            'reports' => $paginated,
            'counters' => $counters,
        ];
    }

    /**
     * Retourne la fiche détaillée d'un dossier de modération par référence ou identifiant.
     */
    public function getCaseDetail(string|int $ref): array
    {
        /** @var Report|null $report */
        $report = Report::query()
            ->where('reference', $ref)
            ->orWhere('id', is_numeric($ref) ? (int) $ref : 0)
            ->with([
                'reporter:id,name,email,avatar,created_at',
                'assignedOperator:id,name,email',
                'escalatedOperator:id,name,email',
                'resolver:id,name,email',
                'decisions.operator:id,name,email',
                'evidences.uploader:id,name,email',
            ])
            ->first();

        if (! $report) {
            throw new NotFoundHttpException("Dossier de modération '{$ref}' introuvable.");
        }

        // Résolution sécurisée du contenu polymorphique
        $content = null;
        $contentAuthor = null;
        $campaignData = null;

        $targetClass = Report::resolveReportableClass($report->reportable_type);
        if ($targetClass && class_exists($targetClass)) {
            $content = $targetClass::find($report->reportable_id);

            if ($content) {
                // Résolution de l'auteur
                if (method_exists($content, 'user')) {
                    $contentAuthor = $content->user ? [
                        'id' => $content->user->id,
                        'name' => $content->user->name,
                        'email' => $content->user->email,
                        'status' => $content->user->status ?? 'active',
                    ] : null;
                } elseif (isset($content->user_id)) {
                    $authorUser = User::find($content->user_id);
                    $contentAuthor = $authorUser ? [
                        'id' => $authorUser->id,
                        'name' => $authorUser->name,
                        'email' => $authorUser->email,
                        'status' => $authorUser->status ?? 'active',
                    ] : null;
                } elseif ($content instanceof User) {
                    $contentAuthor = [
                        'id' => $content->id,
                        'name' => $content->name,
                        'email' => $content->email,
                        'status' => $content->status ?? 'active',
                    ];
                }

                // Vérification du statut Boost (BO)
                $activeCampaign = Campaign::where('boostable_type', $content->getMorphClass())
                    ->where('boostable_id', (string) $content->getKey())
                    ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Pending->value])
                    ->first();

                if ($activeCampaign) {
                    $campaignData = [
                        'id' => $activeCampaign->id,
                        'status' => $activeCampaign->status?->value ?? $activeCampaign->status,
                        'budget_amount' => (float) $activeCampaign->budget_amount,
                        'spent_amount' => (float) $activeCampaign->spent_amount,
                        'remaining_budget' => max(0.0, (float) $activeCampaign->budget_amount - (float) $activeCampaign->spent_amount),
                    ];
                }
            }
        }

        // Vérification de recours ouvert
        $appeal = ModerationAppeal::where('sanction_reference', $report->reference)
            ->orWhere(function ($q) use ($report) {
                $q->where('sanction_reference', (string) $report->id);
            })
            ->first();

        return [
            'case' => $report,
            'content' => $content,
            'content_author' => $contentAuthor,
            'boost' => $campaignData,
            'appeal' => $appeal,
        ];
    }

    /**
     * Applique une décision validée sur un dossier de modération.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function applyDecision(
        Report $report,
        string $action,
        string $justification,
        User $operator,
        array $metadata = []
    ): ModerationDecision {
        $allowedActions = ['keep_online', 'hide', 'remove', 'warn_author', 'suspend_author'];
        if (! in_array($action, $allowedActions, true)) {
            throw new InvalidArgumentException("Action de modération '{$action}' invalide.");
        }

        if (empty(trim($justification))) {
            throw new InvalidArgumentException('Une justification est obligatoire pour toute décision de modération.');
        }

        return DB::transaction(function () use ($report, $action, $justification, $operator, $metadata) {
            /** @var Report $lockedReport */
            $lockedReport = Report::where('id', $report->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedReport->status, [ReportStatus::Resolved, ReportStatus::Dismissed], true)) {
                throw new InvalidArgumentException("Le dossier {$lockedReport->reference} a déjà été traité.");
            }

            // 1. Résolution du contenu polymorphique
            $targetClass = Report::resolveReportableClass($lockedReport->reportable_type);
            $content = $targetClass ? $targetClass::find($lockedReport->reportable_id) : null;

            $decisionMetadata = array_merge($metadata, [
                'previous_status' => $lockedReport->status?->value ?? (string) $lockedReport->status,
                'target_type' => $lockedReport->reportable_type,
                'target_id' => $lockedReport->reportable_id,
            ]);

            // 2. Application de l'action sur le contenu ou l'auteur
            if ($content) {
                switch ($action) {
                    case 'remove':
                        // Soft delete si supporté
                        if (method_exists($content, 'delete')) {
                            $content->delete();
                        }
                        break;

                    case 'hide':
                        if (isset($content->is_archived)) {
                            $content->is_archived = true;
                            $content->save();
                        } elseif (isset($content->status)) {
                            $content->status = 'suspended';
                            $content->save();
                        }
                        break;

                    case 'suspend_author':
                        $authorId = $content->user_id ?? ($content instanceof User ? $content->id : null);
                        if ($authorId) {
                            $author = User::find($authorId);
                            if ($author) {
                                $author->status = 'suspended';
                                $author->save();
                            }
                        }
                        if (method_exists($content, 'delete')) {
                            $content->delete();
                        }
                        break;

                    case 'warn_author':
                        // Enregistrement de l'avertissement
                        $decisionMetadata['warning_sent'] = true;
                        break;

                    case 'keep_online':
                        // Aucun changement sur le contenu
                        break;
                }

                // 3. Gestion de l'impact Boost (BO) & Restitution éventuelle
                $campaigns = Campaign::where('boostable_type', $content->getMorphClass())
                    ->where('boostable_id', (string) $content->getKey())
                    ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Pending->value])
                    ->get();

                foreach ($campaigns as $campaign) {
                    // Arrêt ou suspension de la campagne
                    $this->campaignService->suspendCampaign($campaign, 'moderation');

                    // Restitution de crédits si expressément autorisée dans les métadonnées de décision
                    if (! empty($metadata['refund_credits']) && $metadata['refund_credits'] === true) {
                        $unspent = max(0.0, (float) $campaign->budget_amount - (float) $campaign->spent_amount);
                        if ($unspent > 0) {
                            $idempotencyKey = "mod_refund_{$lockedReport->reference}_{$campaign->id}";
                            $this->creditWalletService->credit(
                                account: $campaign->user ?? User::findOrFail($campaign->user_id),
                                amount: $unspent,
                                type: CreditTransactionType::CampaignRefund,
                                description: "Restitution suite modération dossier {$lockedReport->reference}",
                                idempotencyKey: $idempotencyKey,
                                reference: $campaign,
                                metadata: [
                                    'report_reference' => $lockedReport->reference,
                                    'campaign_id' => $campaign->id,
                                    'moderator_id' => $operator->id,
                                ]
                            );
                            $decisionMetadata['refunded_credits'] = $unspent;
                            $decisionMetadata['refund_idempotency_key'] = $idempotencyKey;
                        }
                    }
                }
            }

            // 4. Mise à jour du dossier de signalement
            $lockedReport->status = $action === 'keep_online' ? ReportStatus::Dismissed : ReportStatus::Resolved;
            $lockedReport->action_taken = $action;
            $lockedReport->resolved_by = $operator->id;
            $lockedReport->resolved_at = now();
            $lockedReport->save();

            // 5. Enregistrement dans l'historique des décisions
            $decision = ModerationDecision::create([
                'report_id' => $lockedReport->id,
                'operator_id' => $operator->id,
                'action' => $action,
                'justification' => $justification,
                'metadata' => $decisionMetadata,
                'created_at' => now(),
            ]);

            // 6. Journalisation dans les logs d'audit administratifs
            AdminAuditLog::logEvent('moderation.decision', $operator, request()->ip(), request()->userAgent(), [
                'report_reference' => $lockedReport->reference,
                'action' => $action,
                'target_type' => $lockedReport->reportable_type,
                'target_id' => $lockedReport->reportable_id,
                'justification' => $justification,
            ]);

            return $decision;
        });
    }

    /**
     * Traitement groupé de dossiers de modération.
     *
     * @param  array<string>  $caseRefs
     * @return array{processed: array<string>, skipped: array<string>, errors: array<string, string>}
     */
    public function applyBulkDecisions(array $caseRefs, string $action, string $justification, User $operator): array
    {
        $maxLimit = 50;
        if (count($caseRefs) > $maxLimit) {
            throw new InvalidArgumentException("Le traitement groupé est limité à {$maxLimit} dossiers simultanés.");
        }

        $processed = [];
        $skipped = [];
        $errors = [];

        foreach ($caseRefs as $ref) {
            try {
                $report = Report::where('reference', $ref)
                    ->orWhere('id', is_numeric($ref) ? (int) $ref : 0)
                    ->first();

                if (! $report) {
                    $errors[$ref] = 'Dossier introuvable.';

                    continue;
                }

                if (in_array($report->status, [ReportStatus::Resolved, ReportStatus::Dismissed], true)) {
                    $skipped[] = $report->reference ?? (string) $report->id;

                    continue;
                }

                $this->applyDecision($report, $action, $justification, $operator);
                $processed[] = $report->reference ?? (string) $report->id;
            } catch (\Throwable $e) {
                $errors[$ref] = $e->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Liste des opérateurs disponibles pour assignation de dossiers.
     */
    public function getAssignees(): Collection
    {
        $moderationRole = Role::where('slug', 'moderation')->first();
        $adminRole = Role::where('slug', 'admin')->first();

        $roleIds = array_filter([$moderationRole?->id, $adminRole?->id]);

        return User::query()
            ->whereIn('role_id', $roleIds)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->withCount([
                'assignedReports as active_cases_count' => function ($q) {
                    $q->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Investigating->value]);
                },
            ])
            ->get(['id', 'name', 'email', 'avatar', 'role_id']);
    }

    /**
     * Assigne un ou plusieurs dossiers à un opérateur de modération.
     *
     * @param  array<string>  $caseRefs
     * @return array<string>
     */
    public function assignCases(array $caseRefs, int $operatorId, User $currentOperator): array
    {
        $assignee = User::findOrFail($operatorId);

        $assigned = [];
        foreach ($caseRefs as $ref) {
            $report = Report::where('reference', $ref)
                ->orWhere('id', is_numeric($ref) ? (int) $ref : 0)
                ->first();

            if ($report) {
                $report->assigned_to = $assignee->id;
                $report->status = ReportStatus::Investigating;
                $report->save();

                AdminAuditLog::logEvent('moderation.assign', $currentOperator, request()->ip(), request()->userAgent(), [
                    'report_reference' => $report->reference,
                    'assigned_to' => $assignee->email,
                ]);

                $assigned[] = $report->reference ?? (string) $report->id;
            }
        }

        return $assigned;
    }

    /**
     * Escalade un dossier de modération vers un superviseur/responsable.
     */
    public function escalateCase(Report $report, int $seniorOperatorId, string $reason, User $currentOperator): Report
    {
        $senior = User::findOrFail($seniorOperatorId);

        $report->escalated_to = $senior->id;
        $report->escalation_reason = $reason;
        $report->priority = 'urgent';
        $report->status = ReportStatus::Investigating;
        $report->save();

        AdminAuditLog::logEvent('moderation.escalate', $currentOperator, request()->ip(), request()->userAgent(), [
            'report_reference' => $report->reference,
            'escalated_to' => $senior->email,
            'reason' => $reason,
        ]);

        return $report;
    }

    /**
     * Retourne l'historique paginé des décisions prises par un opérateur.
     */
    public function getMyDecisions(User $operator, int $perPage = 20): LengthAwarePaginator
    {
        return ModerationDecision::query()
            ->where('operator_id', $operator->id)
            ->with(['report.reporter:id,name,email'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Exporte les données d'activité de l'opérateur.
     *
     * @return array<string, mixed>
     */
    public function exportMyActivity(User $operator, ?string $from = null, ?string $to = null): array
    {
        $query = ModerationDecision::query()->where('operator_id', $operator->id);

        if ($from) {
            $query->whereDate('created_at', '>=', Carbon::parse($from)->toDateString());
        }
        if ($to) {
            $query->whereDate('created_at', '<=', Carbon::parse($to)->toDateString());
        }

        $decisions = $query->orderByDesc('created_at')->limit(1000)->get();

        AdminAuditLog::logEvent('moderation.export', $operator, request()->ip(), request()->userAgent(), [
            'count' => $decisions->count(),
            'from' => $from,
            'to' => $to,
        ]);

        return [
            'operator' => [
                'id' => $operator->id,
                'name' => $operator->name,
                'email' => $operator->email,
            ],
            'exported_at' => now()->toIso8601String(),
            'total_decisions' => $decisions->count(),
            'decisions' => $decisions,
        ];
    }
}
