<?php

namespace App\Services\Admin;

use App\Enums\Admin\AdminPermission;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Enums\Listing\ListingStatus;
use App\Enums\Page\PageStatus;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminAuditLog;
use App\Models\Auth\Role;
use App\Models\Boost\Campaign;
use App\Models\Listing\Listing;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationDecision;
use App\Models\Support\Report;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use App\Notifications\Support\ModerationDecisionNotification;
use App\Services\Boost\CampaignService;
use App\Services\Boost\CreditWalletService;
use App\Services\Page\PageService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ModerationQueueService
{
    public function __construct(
        protected CampaignService $campaignService,
        protected CreditWalletService $creditWalletService,
        protected ?PageService $pageService = null
    ) {
        $this->pageService = $pageService ?? app(PageService::class);
    }

    /**
     * Récupère la file de signalements paginée avec filtres, onglets et compteurs.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getQueue(array $filters, int $perPage = 20): array
    {
        $query = Report::query()->with([
            'reporter:id,name,display_name,handle,avatar_url,city,badge,email',
            'assignedOperator:id,name,email',
        ]);

        // 1. Filtrage par onglet (tab)
        $tab = strtolower($filters['tab'] ?? 'all');
        if ($tab !== 'all') {
            if (in_array($tab, ['comments', 'comment'], true)) {
                $query->whereIn('reportable_type', [
                    PostComment::class,
                    ReelComment::class,
                    'post_comment',
                    'reel_comment',
                    'comment',
                    'comments',
                ]);
            } elseif (in_array($tab, ['accounts', 'account', 'users', 'user', 'profile'], true)) {
                $query->whereIn('reportable_type', [
                    User::class,
                    'user',
                    'users',
                    'account',
                    'accounts',
                    'profile',
                ]);
            } elseif (in_array($tab, ['pages', 'page'], true)) {
                $query->whereIn('reportable_type', [
                    Page::class,
                    'page',
                    'pages',
                ]);
            } else {
                $class = Report::resolveReportableClass($tab);
                if ($class) {
                    $query->where(function ($q) use ($class, $tab) {
                        $q->where('reportable_type', $class)
                            ->orWhere('reportable_type', $tab);
                    });
                }
            }
        }

        // 2. Filtres par motif (reason)
        if (! empty($filters['reason'])) {
            if (is_array($filters['reason'])) {
                $query->whereIn('reason', $filters['reason']);
            } else {
                $query->where('reason', $filters['reason']);
            }
        }

        // 3. Filtre par statut du signalement
        if (! empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        } else {
            // Par défaut, afficher les dossiers ouverts (en attente ou en cours)
            $query->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Investigating->value]);
        }

        // 4. Filtre par priorité
        if (! empty($filters['priority'])) {
            if (is_array($filters['priority'])) {
                $query->whereIn('priority', $filters['priority']);
            } else {
                $query->where('priority', $filters['priority']);
            }
        }

        // 5. Filtre par opérateur assigné
        if (isset($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'unassigned') {
                $query->whereNull('assigned_to');
            } elseif (is_numeric($filters['assigned_to'])) {
                $query->where('assigned_to', (int) $filters['assigned_to']);
            }
        }

        // 6. Filtre par dates (ISO datetime et date simple)
        if (! empty($filters['created_after'])) {
            $val = str_replace(' ', '+', trim($filters['created_after']));
            $query->where('created_at', '>=', Carbon::parse($val));
        } elseif (! empty($filters['date_from'])) {
            $val = str_replace(' ', '+', trim($filters['date_from']));
            $parsed = Carbon::parse($val);
            if (strlen($val) > 10) {
                $query->where('created_at', '>=', $parsed);
            } else {
                $query->whereDate('created_at', '>=', $parsed->toDateString());
            }
        }

        if (! empty($filters['created_before'])) {
            $val = str_replace(' ', '+', trim($filters['created_before']));
            $query->where('created_at', '<=', Carbon::parse($val));
        } elseif (! empty($filters['date_to'])) {
            $val = str_replace(' ', '+', trim($filters['date_to']));
            $parsed = Carbon::parse($val);
            if (strlen($val) > 10) {
                $query->where('created_at', '<=', $parsed);
            } else {
                $query->whereDate('created_at', '<=', $parsed->toDateString());
            }
        }

        // 7. Recherche par référence, contenu, signalant ou compte visé
        $search = trim($filters['q'] ?? $filters['search'] ?? '');
        if ($search !== '') {
            $matchingUserIds = User::query()
                ->where('name', 'LIKE', "%{$search}%")
                ->orWhere('display_name', 'LIKE', "%{$search}%")
                ->orWhere('handle', 'LIKE', "%{$search}%")
                ->orWhere('email', 'LIKE', "%{$search}%")
                ->pluck('id');

            $query->where(function ($q) use ($search, $matchingUserIds) {
                // Référence, identifiant technique et texte du signalement
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhere('reportable_id', 'LIKE', "%{$search}%")
                    ->orWhere('details', 'LIKE', "%{$search}%");

                if ($matchingUserIds->isNotEmpty()) {
                    // Signalant (reporter)
                    $q->orWhereIn('reporter_id', $matchingUserIds);

                    // Compte visé direct (User)
                    $q->orWhere(function ($sub) use ($matchingUserIds) {
                        $sub->whereIn('reportable_type', [User::class, 'user', 'users', 'account', 'accounts', 'profile'])
                            ->whereIn('reportable_id', $matchingUserIds);
                    });

                    // Auteur de publication visée
                    $postIds = Post::whereIn('user_id', $matchingUserIds)->pluck('id');
                    if ($postIds->isNotEmpty()) {
                        $q->orWhere(function ($sub) use ($postIds) {
                            $sub->whereIn('reportable_type', [Post::class, 'post', 'posts'])
                                ->whereIn('reportable_id', $postIds);
                        });
                    }

                    // Auteur de reel visé
                    $reelIds = Reel::whereIn('user_id', $matchingUserIds)->pluck('id');
                    if ($reelIds->isNotEmpty()) {
                        $q->orWhere(function ($sub) use ($reelIds) {
                            $sub->whereIn('reportable_type', [Reel::class, 'reel', 'reels'])
                                ->whereIn('reportable_id', $reelIds);
                        });
                    }

                    // Auteur d'annonce visée
                    $listingIds = Listing::whereIn('user_id', $matchingUserIds)->pluck('id');
                    if ($listingIds->isNotEmpty()) {
                        $q->orWhere(function ($sub) use ($listingIds) {
                            $sub->whereIn('reportable_type', [Listing::class, 'listing', 'listings'])
                                ->whereIn('reportable_id', $listingIds);
                        });
                    }

                    // Auteur de commentaire visé
                    $postCommentIds = PostComment::whereIn('user_id', $matchingUserIds)->pluck('id');
                    $reelCommentIds = ReelComment::whereIn('user_id', $matchingUserIds)->pluck('id');
                    $allCommentIds = $postCommentIds->merge($reelCommentIds);
                    if ($allCommentIds->isNotEmpty()) {
                        $q->orWhere(function ($sub) use ($allCommentIds) {
                            $sub->whereIn('reportable_type', [PostComment::class, ReelComment::class, 'post_comment', 'reel_comment', 'comment', 'comments'])
                                ->whereIn('reportable_id', $allCommentIds);
                        });
                    }

                    // Pages visées dont le propriétaire correspond à la recherche
                    $pageIdsByOwner = Page::whereIn('owner_id', $matchingUserIds)->pluck('id');
                    if ($pageIdsByOwner->isNotEmpty()) {
                        $q->orWhere(function ($sub) use ($pageIdsByOwner) {
                            $sub->whereIn('reportable_type', [Page::class, 'page', 'pages'])
                                ->whereIn('reportable_id', $pageIdsByOwner);
                        });
                    }
                }

                // Recherche directe par nom ou handle de la Page visée
                $matchingPageIds = Page::query()
                    ->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('handle', 'LIKE', "%{$search}%")
                    ->pluck('id');
                if ($matchingPageIds->isNotEmpty()) {
                    $q->orWhere(function ($sub) use ($matchingPageIds) {
                        $sub->whereIn('reportable_type', [Page::class, 'page', 'pages'])
                            ->whereIn('reportable_id', $matchingPageIds);
                    });
                }
            });
        }

        // 8. Tri contrôlé (liste blanche stricte)
        $allowedSorts = ['created_at', 'priority', 'status', 'reference', 'id'];
        $sortBy = in_array($filters['sort_by'] ?? $filters['sort'] ?? '', $allowedSorts, true) ? ($filters['sort_by'] ?? $filters['sort']) : 'created_at';
        $sortDir = strtolower($filters['sort_dir'] ?? $filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginated = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        // Préchargement groupé des cibles polymorphiques pour éviter les requêtes N+1
        $this->eagerLoadTargets($paginated->getCollection());

        // 9. Calcul des compteurs KPI de la file (limités aux dossiers ouverts : pending, investigating)
        $openStatuses = [ReportStatus::Pending->value, ReportStatus::Investigating->value];
        $openQuery = Report::query()->whereIn('status', $openStatuses);

        $postTypes = [Post::class, 'post', 'posts'];
        $reelTypes = [Reel::class, 'reel', 'reels'];
        $listingTypes = [Listing::class, 'listing', 'listings'];
        $messageTypes = [Message::class, 'message', 'messages'];
        $commentTypes = [PostComment::class, ReelComment::class, 'post_comment', 'reel_comment', 'comment', 'comments'];
        $accountTypes = [User::class, 'user', 'users', 'account', 'accounts', 'profile'];
        $pageTypes = [Page::class, 'page', 'pages'];

        $counters = [
            'total_pending' => Report::where('status', ReportStatus::Pending->value)->count(),
            'total_investigating' => Report::where('status', ReportStatus::Investigating->value)->count(),
            'urgent_count' => (clone $openQuery)->where('priority', 'urgent')->count(),
            'unassigned_count' => (clone $openQuery)->whereNull('assigned_to')->count(),
            'all' => (clone $openQuery)->count(),
            'posts' => (clone $openQuery)->whereIn('reportable_type', $postTypes)->count(),
            'reels' => (clone $openQuery)->whereIn('reportable_type', $reelTypes)->count(),
            'listings' => (clone $openQuery)->whereIn('reportable_type', $listingTypes)->count(),
            'messages' => (clone $openQuery)->whereIn('reportable_type', $messageTypes)->count(),
            'comments' => (clone $openQuery)->whereIn('reportable_type', $commentTypes)->count(),
            'accounts' => (clone $openQuery)->whereIn('reportable_type', $accountTypes)->count(),
            'pages' => (clone $openQuery)->whereIn('reportable_type', $pageTypes)->count(),
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
                'reporter:id,name,display_name,handle,avatar_url,city,badge,email,created_at',
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
                // Résolution robuste de l'auteur (y compris propriétaire d'annonce owner_id ou de page)
                $authorModel = $this->resolveContentAuthor($content);

                if ($authorModel) {
                    $contentAuthor = [
                        'id' => $authorModel->id,
                        'name' => $authorModel->name,
                        'display_name' => $authorModel->display_name ?: $authorModel->name,
                        'handle' => $authorModel->handle,
                        'avatar_url' => $authorModel->avatar_url,
                        'city' => $authorModel->city,
                        'badge' => $authorModel->badge,
                        'email' => $authorModel->email,
                        'status' => $authorModel->status ?? 'active',
                    ];
                }

                // Extraction des médias du contenu (photos / vidéos) sous la forme [{ type, url, poster_url }]
                $mediaList = $this->extractContentMedia($content);
                $content->unsetRelation('media');
                $content->setRelation('media', collect($mediaList));
                $content->setAttribute('media', $mediaList);

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

        // Vérification de recours ouvert strictement rattaché à une décision de ce dossier
        $decisionIds = $report->decisions->pluck('id')->filter()->all();
        $appeal = ! empty($decisionIds)
            ? ModerationAppeal::whereIn('moderation_decision_id', $decisionIds)->first()
            : null;

        $targetType = Report::resolveShortType($report->reportable_type);
        $availableActions = $this->getDetailedAvailableActions($report, $content);

        return [
            'case' => $report,
            'content' => $content,
            'content_author' => $contentAuthor,
            'boost' => $campaignData,
            'appeal' => $appeal,
            'target_type' => $targetType,
            'available_actions' => $availableActions,
            'actions' => $availableActions,
        ];
    }

    /**
     * Retourne la liste des actions de modération disponibles et contextualisées pour un dossier.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDetailedAvailableActions(Report $report, mixed $content = null): array
    {
        $shortType = Report::resolveShortType($report->reportable_type);

        if ($shortType === 'page' || ($content instanceof Page)) {
            $isSuspended = ($content instanceof Page) && $content->status === PageStatus::Suspended;

            return [
                [
                    'action' => 'warn_page',
                    'label' => 'Avertir la page',
                    'description' => 'Notifier un avertissement officiel au propriétaire sans impacter son compte personnel ni supprimer la page.',
                ],
                [
                    'action' => 'suspend_page',
                    'label' => 'Suspendre la page',
                    'description' => 'Suspendre la page professionnelle (pages.status = suspended) sans toucher au compte du propriétaire ni supprimer la page.',
                    'recommended' => ! $isSuspended,
                ],
                [
                    'action' => 'unsuspend_page',
                    'label' => 'Lever la suspension',
                    'description' => 'Rétablir le statut actif de la page professionnelle précédemment suspendue.',
                    'recommended' => $isSuspended,
                ],
                [
                    'action' => 'keep_online',
                    'label' => 'Maintenir en ligne / Sans suite',
                    'description' => 'Classer le dossier sans suite et maintenir la page en ligne.',
                ],
                [
                    'action' => 'request_correction',
                    'label' => 'Demander une correction',
                    'description' => 'Demander au propriétaire de mettre sa page en conformité dans un délai imparti.',
                ],
            ];
        }

        if ($shortType === 'user' || ($content instanceof User)) {
            return [
                [
                    'action' => 'warn_author',
                    'label' => 'Avertir l\'utilisateur',
                    'description' => 'Émettre un avertissement officiel au compte.',
                ],
                [
                    'action' => 'suspend_author',
                    'label' => 'Suspendre le compte',
                    'description' => 'Suspendre temporairement le compte personnel.',
                ],
                [
                    'action' => 'restrict_author',
                    'label' => 'Restreindre le compte',
                    'description' => 'Restreindre les interactions du compte sans suspendre sa session.',
                ],
                [
                    'action' => 'keep_online',
                    'label' => 'Maintenir en ligne / Sans suite',
                    'description' => 'Classer le signalement sans suite.',
                ],
                [
                    'action' => 'request_correction',
                    'label' => 'Demander une correction',
                    'description' => 'Demander une mise en conformité du profil.',
                ],
            ];
        }

        return [
            [
                'action' => 'keep_online',
                'label' => 'Maintenir en ligne',
                'description' => 'Classer le signalement sans suite et conserver le contenu visible.',
            ],
            [
                'action' => 'hide',
                'label' => 'Masquer le contenu',
                'description' => 'Rendre le contenu inaccessible au public.',
            ],
            [
                'action' => 'remove',
                'label' => 'Supprimer le contenu',
                'description' => 'Supprimer définitivement le contenu incriminé.',
            ],
            [
                'action' => 'warn_author',
                'label' => 'Avertir l\'auteur',
                'description' => 'Envoyer un avertissement officiel à l\'auteur.',
            ],
            [
                'action' => 'suspend_author',
                'label' => 'Suspendre l\'auteur',
                'description' => 'Suspendre le compte personnel de l\'auteur.',
            ],
            [
                'action' => 'request_correction',
                'label' => 'Demander une correction',
                'description' => 'Demander la modification du contenu sous 24h à 72h.',
            ],
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
        $allowedActions = [
            'keep_online',
            'hide',
            'remove',
            'warn_author',
            'suspend_author',
            'restrict_author',
            'restriction',
            'request_correction',
            'warn_page',
            'suspend_page',
            'unsuspend_page',
            'lift_suspension',
            'warn',
            'suspend',
            'unsuspend',
            'lift',
        ];
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

            // Validation des actions admissibles selon le type de cible
            $shortType = Report::resolveShortType($lockedReport->reportable_type);
            $isTargetUser = in_array(strtolower($lockedReport->reportable_type), ['user', 'app\\models\\user', 'users', 'account'], true);
            $isTargetPage = in_array($shortType, ['page'], true)
                || in_array(strtolower($lockedReport->reportable_type), ['page', 'pages', 'app\\models\\page\\page'], true)
                || ($content instanceof Page);

            if ($isTargetUser && in_array($action, ['remove', 'hide', 'suspend_page', 'unsuspend_page', 'warn_page'], true)) {
                throw new InvalidArgumentException("L'action '{$action}' n'est pas applicable à un profil utilisateur. Utilisez la suspension d'auteur ou l'avertissement.");
            }

            if ($isTargetPage && in_array($action, ['remove', 'hide', 'restrict_author', 'restriction'], true)) {
                throw new InvalidArgumentException("L'action '{$action}' n'est pas applicable à une page professionnelle. Utilisez la suspension de la page ou l'avertissement.");
            }

            $decisionMetadata = array_merge($metadata, [
                'previous_status' => $lockedReport->status?->value ?? (string) $lockedReport->status,
                'target_type' => $lockedReport->reportable_type,
                'target_id' => $lockedReport->reportable_id,
            ]);

            // 2. Application de l'action sur le contenu ou l'auteur
            if ($content) {
                switch ($action) {
                    case 'remove':
                    case 'hide':
                        if ($isTargetPage || ($content instanceof Page)) {
                            throw new InvalidArgumentException("L'action '{$action}' n'est pas applicable à une page professionnelle. Utilisez la suspension de page ou l'avertissement.");
                        }

                        // 1. Extraire le texte (quote), l'emplacement (where) et la règle
                        $quote = $metadata['quote'] ?? ($content->body ?? $content->description ?? $content->title ?? $content->content ?? null);
                        $where = $metadata['where'] ?? Report::resolveShortType(get_class($content));
                        $rule = $metadata['rule'] ?? ($lockedReport->reason?->value ?? (string) $lockedReport->reason);
                        $ruleBody = $metadata['rule_body'] ?? ($lockedReport->reason instanceof ReportReason ? $lockedReport->reason->label() : $justification);

                        // 2. Action sur le contenu
                        if ($action === 'remove') {
                            if (! ($content instanceof User) && ! ($content instanceof Page) && method_exists($content, 'delete')) {
                                $content->delete();
                            }
                        } else {
                            if ($content instanceof Listing) {
                                $content->status = ListingStatus::Draft;
                                $content->rejection_reason = $justification;
                                $content->save();
                            } elseif (isset($content->is_archived)) {
                                $content->is_archived = true;
                                $content->save();
                            } elseif (isset($content->status)) {
                                $content->status = 'rejected';
                                $content->save();
                            }
                        }

                        // 3. Notifier l'auteur et enregistrer la sanction content_removal pour l'écran SUP-20
                        $author = $this->resolveContentAuthor($content);
                        if ($author) {
                            UserAccountSanction::create([
                                'user_id' => $author->id,
                                'operator_id' => $operator->id,
                                'type' => 'content_removal',
                                'level' => 1,
                                'starts_at' => now(),
                                'reason' => $justification,
                                'is_active' => true,
                                'metadata' => [
                                    'report_reference' => $lockedReport->reference,
                                    'target_type' => $lockedReport->reportable_type,
                                    'target_id' => $lockedReport->reportable_id,
                                    'quote' => $quote,
                                    'where' => $where,
                                    'rule' => $rule,
                                    'rule_body' => $ruleBody,
                                ],
                            ]);

                            try {
                                $author->notify(new ModerationDecisionNotification($lockedReport, $action, $justification));
                            } catch (\Throwable $e) {
                                Log::warning("Échec notification {$action} contenu auteur : ".$e->getMessage());
                            }
                        }
                        break;

                    case 'restrict_author':
                    case 'restriction':
                        if ($isTargetPage || ($content instanceof Page)) {
                            throw new InvalidArgumentException("L'action '{$action}' n'est pas applicable à une page professionnelle. Utilisez la suspension de page.");
                        }

                        $durationDays = (int) ($metadata['duration_days'] ?? 7);
                        $restrictedUntil = now()->addDays($durationDays);
                        $author = $this->resolveContentAuthor($content);

                        if ($author) {
                            // SUP-21 : Restreindre sans toucher au statut (status reste actif/inchangé)
                            $author->restricted_until = $restrictedUntil;
                            $author->save();

                            UserAccountSanction::create([
                                'user_id' => $author->id,
                                'operator_id' => $operator->id,
                                'type' => 'restriction',
                                'level' => 2,
                                'duration_days' => $durationDays,
                                'starts_at' => now(),
                                'ends_at' => $restrictedUntil,
                                'reason' => $justification,
                                'is_active' => true,
                                'metadata' => [
                                    'report_reference' => $lockedReport->reference,
                                    'target_type' => $lockedReport->reportable_type,
                                    'target_id' => $lockedReport->reportable_id,
                                    'restricted_until' => $restrictedUntil->toIso8601String(),
                                    'quote' => $metadata['quote'] ?? ($content->body ?? $content->description ?? $content->title ?? null),
                                    'where' => $metadata['where'] ?? Report::resolveShortType(get_class($content)),
                                    'rule' => $metadata['rule'] ?? ($lockedReport->reason?->value ?? (string) $lockedReport->reason),
                                    'rule_body' => $metadata['rule_body'] ?? ($lockedReport->reason instanceof ReportReason ? $lockedReport->reason->label() : $justification),
                                ],
                            ]);

                            try {
                                $author->notify(new ModerationDecisionNotification($lockedReport, 'restriction', $justification, $restrictedUntil));
                            } catch (\Throwable $e) {
                                Log::warning('Échec notification restriction auteur : '.$e->getMessage());
                            }
                        }

                        // Masquer ou retirer le contenu incriminé (jamais une page)
                        if (! ($content instanceof User) && ! ($content instanceof Page)) {
                            if ($content instanceof Listing) {
                                $content->status = ListingStatus::Draft;
                                $content->rejection_reason = $justification;
                                $content->save();
                            } elseif (isset($content->is_archived)) {
                                $content->is_archived = true;
                                $content->save();
                            } elseif (method_exists($content, 'delete')) {
                                $content->delete();
                            }
                        }
                        break;

                    case 'suspend_page':
                    case 'suspend':
                    case 'suspend_author':
                        if ($isTargetPage || ($content instanceof Page)) {
                            if ($content instanceof Page) {
                                $this->pageService->suspendPage($content, $operator, $justification);
                                $decisionMetadata['page_id'] = $content->id;
                                $decisionMetadata['page_status'] = 'suspended';

                                try {
                                    $content->owner?->notify(new ModerationDecisionNotification($lockedReport, 'suspend_page', $justification));
                                } catch (\Throwable $e) {
                                    Log::warning('Échec notification ModerationDecisionNotification suspend_page : '.$e->getMessage());
                                }
                            }
                            $action = 'suspend_page';
                            break;
                        }

                        $durationDays = (int) ($metadata['duration_days'] ?? 14);
                        $suspendedUntil = now()->addDays($durationDays);
                        $author = $this->resolveContentAuthor($content);

                        if ($author) {
                            $author->status = 'suspended';
                            $author->suspended_from = now();
                            $author->suspended_until = $suspendedUntil;
                            $author->restricted_until = $suspendedUntil;
                            $author->save();

                            UserAccountSanction::create([
                                'user_id' => $author->id,
                                'operator_id' => $operator->id,
                                'type' => 'suspension',
                                'level' => 2,
                                'duration_days' => $durationDays,
                                'starts_at' => now(),
                                'ends_at' => $suspendedUntil,
                                'reason' => $justification,
                                'is_active' => true,
                                'metadata' => [
                                    'report_reference' => $lockedReport->reference,
                                    'target_type' => $lockedReport->reportable_type,
                                    'target_id' => $lockedReport->reportable_id,
                                ],
                            ]);

                            try {
                                $author->notify(new ModerationDecisionNotification($lockedReport, 'suspend_author', $justification, $suspendedUntil));
                            } catch (\Throwable $e) {
                                Log::warning('Échec notification suspension auteur : '.$e->getMessage());
                            }
                        }

                        // Si le contenu n'est pas le compte utilisateur lui-même, on masque ou retire le contenu (jamais une page)
                        if (! ($content instanceof User) && ! ($content instanceof Page)) {
                            if ($content instanceof Listing) {
                                $content->status = ListingStatus::Draft;
                                $content->rejection_reason = $justification;
                                $content->save();
                            } elseif (isset($content->is_archived)) {
                                $content->is_archived = true;
                                $content->save();
                            } elseif (method_exists($content, 'delete')) {
                                $content->delete();
                            }
                        }
                        break;

                    case 'unsuspend_page':
                    case 'unsuspend':
                    case 'lift_suspension':
                    case 'lift':
                        if ($content instanceof Page) {
                            $this->pageService->unsuspendPage($content, $operator);
                            $decisionMetadata['page_id'] = $content->id;
                            $decisionMetadata['page_status'] = 'active';

                            try {
                                $content->owner?->notify(new ModerationDecisionNotification($lockedReport, 'unsuspend_page', $justification));
                            } catch (\Throwable $e) {
                                Log::warning('Échec notification ModerationDecisionNotification unsuspend_page : '.$e->getMessage());
                            }
                        }
                        $action = 'unsuspend_page';
                        break;

                    case 'warn_page':
                    case 'warn':
                    case 'warn_author':
                        if ($isTargetPage || ($content instanceof Page)) {
                            $decisionMetadata['warning_sent'] = true;
                            if ($content instanceof Page) {
                                $decisionMetadata['page_id'] = $content->id;
                                try {
                                    $content->owner?->notify(new ModerationDecisionNotification($lockedReport, 'warn_page', $justification));
                                } catch (\Throwable $e) {
                                    Log::warning('Échec notification ModerationDecisionNotification warn_page : '.$e->getMessage());
                                }
                            }
                            $action = 'warn_page';
                            break;
                        }

                        $author = $this->resolveContentAuthor($content);
                        if ($author) {
                            UserAccountSanction::create([
                                'user_id' => $author->id,
                                'operator_id' => $operator->id,
                                'type' => 'warning',
                                'level' => 1,
                                'starts_at' => now(),
                                'reason' => $justification,
                                'is_active' => true,
                                'metadata' => [
                                    'report_reference' => $lockedReport->reference,
                                    'target_type' => $lockedReport->reportable_type,
                                    'target_id' => $lockedReport->reportable_id,
                                ],
                            ]);

                            try {
                                $author->notify(new ModerationDecisionNotification($lockedReport, 'warn_author', $justification));
                            } catch (\Throwable $e) {
                                Log::warning('Échec notification avertissement auteur : '.$e->getMessage());
                            }
                        }
                        $decisionMetadata['warning_sent'] = true;
                        break;

                    case 'request_correction':
                        $delayHours = (int) ($metadata['correction_delay_hours'] ?? request()->input('correction_delay_hours', 24));
                        if ($delayHours <= 0) {
                            $delayHours = 24;
                        }
                        $deadline = now()->addHours($delayHours);

                        if ($isTargetPage || ($content instanceof Page)) {
                            $decisionMetadata['correction_delay_hours'] = $delayHours;
                            $decisionMetadata['correction_deadline'] = $deadline->toIso8601String();
                            $decisionMetadata['correction_requested'] = true;

                            if ($content instanceof Page) {
                                $decisionMetadata['page_id'] = $content->id;
                                try {
                                    $content->owner?->notify(new ModerationDecisionNotification($lockedReport, 'request_correction', $justification, $deadline));
                                } catch (\Throwable $e) {
                                    Log::warning('Échec notification demande de correction page : '.$e->getMessage());
                                }
                            }

                            $currentReportMeta = is_array($lockedReport->metadata) ? $lockedReport->metadata : [];
                            $currentReportMeta['correction_deadline'] = $deadline->toIso8601String();
                            $currentReportMeta['correction_delay_hours'] = $delayHours;
                            $lockedReport->metadata = $currentReportMeta;
                            break;
                        }

                        $author = $this->resolveContentAuthor($content);
                        if ($author) {
                            UserAccountSanction::create([
                                'user_id' => $author->id,
                                'operator_id' => $operator->id,
                                'type' => 'correction_requested',
                                'level' => 1,
                                'starts_at' => now(),
                                'ends_at' => $deadline,
                                'reason' => $justification,
                                'is_active' => true,
                                'metadata' => [
                                    'report_reference' => $lockedReport->reference,
                                    'target_type' => $lockedReport->reportable_type,
                                    'target_id' => $lockedReport->reportable_id,
                                    'correction_delay_hours' => $delayHours,
                                    'correction_deadline' => $deadline->toIso8601String(),
                                ],
                            ]);

                            try {
                                $author->notify(new ModerationDecisionNotification($lockedReport, 'request_correction', $justification, $deadline));
                            } catch (\Throwable $e) {
                                Log::warning('Échec notification demande de correction auteur : '.$e->getMessage());
                            }
                        }

                        $decisionMetadata['correction_delay_hours'] = $delayHours;
                        $decisionMetadata['correction_deadline'] = $deadline->toIso8601String();
                        $decisionMetadata['correction_requested'] = true;

                        $currentReportMeta = is_array($lockedReport->metadata) ? $lockedReport->metadata : [];
                        $currentReportMeta['correction_deadline'] = $deadline->toIso8601String();
                        $currentReportMeta['correction_delay_hours'] = $delayHours;
                        $lockedReport->metadata = $currentReportMeta;
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
                    // Arrêt ou suspension de la campagne (sans restitution automatique pour respecter metadata['refund_credits'])
                    $this->campaignService->suspendCampaign($campaign, 'moderation', false);

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
            $lockedReport->status = $action === 'keep_online'
                ? ReportStatus::Dismissed
                : ($action === 'request_correction' ? ReportStatus::Investigating : ReportStatus::Resolved);
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
            ->get(['id', 'name', 'email', 'avatar_url', 'role_id']);
    }

    /**
     * Assigne un ou plusieurs dossiers à un opérateur de modération.
     *
     * @param  array<string>  $caseRefs
     * @return array<string>
     */
    public function assignCases(array $caseRefs, int $operatorId, User $currentOperator): array
    {
        $assignee = User::with('role')->findOrFail($operatorId);

        $isAuthorized = $assignee->isAdmin()
            || in_array($assignee->role?->slug, ['moderation', 'admin', 'direction'], true)
            || $assignee->hasPermission(AdminPermission::ModerationAction->value)
            || $assignee->hasPermission(AdminPermission::ModerationView->value);

        if (! $isAuthorized) {
            throw ValidationException::withMessages([
                'operator_id' => ["L'utilisateur sélectionné n'est pas un opérateur de modération habilité."],
            ]);
        }

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
        $senior = User::with('role')->findOrFail($seniorOperatorId);

        $isAuthorized = $senior->isAdmin()
            || in_array($senior->role?->slug, ['direction', 'admin'], true)
            || $senior->hasPermission(AdminPermission::ApprovalsAction->value)
            || $senior->hasPermission(AdminPermission::ModerationAction->value);

        if (! $isAuthorized) {
            throw ValidationException::withMessages([
                'senior_operator_id' => ["L'opérateur cible n'a pas les habilitations requises pour recevoir une escalade de modération."],
            ]);
        }

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
    public function getMyDecisions(User $operator, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = ModerationDecision::query()
            ->where('operator_id', $operator->id)
            ->with([
                'report.reporter:id,name,display_name,handle,email',
                'appeal',
            ]);

        // Filtre par date
        if (! empty($filters['created_after'])) {
            $val = str_replace(' ', '+', trim($filters['created_after']));
            $query->where('created_at', '>=', Carbon::parse($val));
        } elseif (! empty($filters['date_from'])) {
            $val = str_replace(' ', '+', trim($filters['date_from']));
            $parsed = Carbon::parse($val);
            if (strlen($val) > 10) {
                $query->where('created_at', '>=', $parsed);
            } else {
                $query->whereDate('created_at', '>=', $parsed->toDateString());
            }
        }

        if (! empty($filters['created_before'])) {
            $val = str_replace(' ', '+', trim($filters['created_before']));
            $query->where('created_at', '<=', Carbon::parse($val));
        } elseif (! empty($filters['date_to'])) {
            $val = str_replace(' ', '+', trim($filters['date_to']));
            $parsed = Carbon::parse($val);
            if (strlen($val) > 10) {
                $query->where('created_at', '<=', $parsed);
            } else {
                $query->whereDate('created_at', '<=', $parsed->toDateString());
            }
        }

        // Filtre décisions contestées
        if (! empty($filters['status']) && in_array(strtolower($filters['status']), ['contested', 'appealed', 'has_appeal'], true)) {
            $query->whereHas('appeal');
        }

        $sortDir = strtolower($filters['sort_dir'] ?? $filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('created_at', $sortDir)->paginate($perPage);
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

    /**
     * Précharge par lots tous les modèles cibles polymorphiques pour éviter les requêtes N+1.
     *
     * @param  \Illuminate\Support\Collection<int, Report>  $reports
     */
    protected function eagerLoadTargets(\Illuminate\Support\Collection $reports): void
    {
        if ($reports->isEmpty()) {
            return;
        }

        $grouped = [];
        foreach ($reports as $report) {
            $class = Report::resolveReportableClass($report->reportable_type);
            if ($class) {
                $grouped[$class][] = $report->reportable_id;
            }
        }

        $loaded = [];
        foreach ($grouped as $class => $ids) {
            $uniqueIds = array_unique($ids);
            if (empty($uniqueIds)) {
                continue;
            }

            try {
                if ($class === Post::class) {
                    $items = Post::with(['user:id,name,display_name,handle,avatar_url,city,badge', 'media'])->whereIn('id', $uniqueIds)->get()->keyBy('id');
                } elseif ($class === Reel::class) {
                    $items = Reel::with(['user:id,name,display_name,handle,avatar_url,city,badge'])->whereIn('id', $uniqueIds)->get()->keyBy('id');
                } elseif ($class === Listing::class) {
                    $items = Listing::with(['owner:id,name,display_name,handle,avatar_url,city,badge', 'createdBy:id,name,display_name,handle,avatar_url,city,badge', 'photos'])->whereIn('id', $uniqueIds)->get()->keyBy('id');
                } elseif ($class === PostComment::class) {
                    $items = PostComment::with(['user:id,name,display_name,handle,avatar_url,city,badge'])->whereIn('id', $uniqueIds)->get()->keyBy('id');
                } elseif ($class === ReelComment::class) {
                    $items = ReelComment::with(['user:id,name,display_name,handle,avatar_url,city,badge'])->whereIn('id', $uniqueIds)->get()->keyBy('id');
                } elseif ($class === Message::class) {
                    $items = Message::with(['sender:id,name,display_name,handle,avatar_url,city,badge'])->whereIn('id', $uniqueIds)->get()->keyBy('id');
                } elseif ($class === User::class) {
                    $items = User::select(['id', 'name', 'display_name', 'handle', 'avatar_url', 'city', 'badge', 'bio'])->whereIn('id', $uniqueIds)->get()->keyBy('id');
                } elseif ($class === Page::class) {
                    $items = Page::with(['owner:id,name,display_name,handle,avatar_url,city,badge'])->whereIn('id', $uniqueIds)->get()->keyBy('id');
                } else {
                    $items = $class::whereIn('id', $uniqueIds)->get()->keyBy('id');
                }
                $loaded[$class] = $items;
            } catch (\Throwable $e) {
                Log::warning("Erreur lors du préchargement polymorphique modération pour {$class}: ".$e->getMessage());
            }
        }

        foreach ($reports as $report) {
            $class = Report::resolveReportableClass($report->reportable_type);
            if ($class && isset($loaded[$class][$report->reportable_id])) {
                $report->setRelation('reportable', $loaded[$class][$report->reportable_id]);
            }
        }
    }

    /**
     * Résout l'auteur légitime d'un contenu (y compris propriétaire owner_id d'une annonce ou d'une page).
     */
    public function resolveContentAuthor(mixed $content): ?User
    {
        if (! $content) {
            return null;
        }

        if ($content instanceof User) {
            return $content;
        }

        // Pour une annonce immobilière : propriétaire du bien/de l'annonce (owner_id)
        if ($content instanceof Listing) {
            if ($content->relationLoaded('owner') && $content->owner) {
                return $content->owner;
            }
            if (! empty($content->owner_id)) {
                return User::find($content->owner_id);
            }
            if (method_exists($content, 'owner') && $content->owner) {
                return $content->owner;
            }
        }

        // Pour une page professionnelle : auteur/propriétaire de la page (owner_id)
        if ($content instanceof Page) {
            if (! empty($content->owner_id)) {
                return User::find($content->owner_id);
            }
            if (method_exists($content, 'owner') && $content->owner) {
                return $content->owner;
            }
        }

        // Publications Square, Reels, Commentaires, etc.
        if (isset($content->user_id) && ! empty($content->user_id)) {
            return (method_exists($content, 'user') && $content->user) ? $content->user : User::find($content->user_id);
        }

        if (isset($content->owner_id) && ! empty($content->owner_id)) {
            return (method_exists($content, 'owner') && $content->owner) ? $content->owner : User::find($content->owner_id);
        }

        if (method_exists($content, 'user') && $content->user) {
            return $content->user;
        }

        if (method_exists($content, 'owner') && $content->owner) {
            return $content->owner;
        }

        return null;
    }

    /**
     * Extrait les médias (photos / vidéos) associés au contenu signalé sous la forme [{ type, url, poster_url }].
     *
     * @return array<int, array{type: string, url: string, poster_url: ?string}>
     */
    public function extractContentMedia(mixed $content): array
    {
        if (! $content) {
            return [];
        }

        $mediaList = [];

        // 1. Annonce immobilière (Listing)
        if ($content instanceof Listing) {
            $content->loadMissing('media');
            foreach ($content->media as $m) {
                $type = ($m->type === 'video' || str_starts_with($m->mime_type ?? '', 'video')) ? 'video' : 'image';
                $mediaList[] = [
                    'type' => $type,
                    'url' => (string) $m->url,
                    'poster_url' => $m->thumbnail_url ? (string) $m->thumbnail_url : null,
                ];
            }

            return $mediaList;
        }

        // 2. Publication Square (Post)
        if ($content instanceof Post) {
            $content->loadMissing('media');
            foreach ($content->media as $m) {
                $rawType = $m->media_type ?? $m->type ?? 'image';
                $type = ($rawType === 'video' || str_starts_with($m->mime_type ?? '', 'video')) ? 'video' : 'image';
                $mediaList[] = [
                    'type' => $type,
                    'url' => (string) ($m->media_url ?? $m->url),
                    'poster_url' => $m->poster_url ? (string) $m->poster_url : ($m->thumbnail_url ? (string) $m->thumbnail_url : null),
                ];
            }

            return $mediaList;
        }

        // 3. Vidéo Reel
        if ($content instanceof Reel) {
            if (! empty($content->video_url)) {
                $mediaList[] = [
                    'type' => 'video',
                    'url' => (string) $content->video_url,
                    'poster_url' => $content->thumbnail_url ? (string) $content->thumbnail_url : null,
                ];
            }

            return $mediaList;
        }

        // 4. Message de conversation
        if ($content instanceof Message) {
            $url = $content->attachment_url ?? ($content->attachment_path ? (str_starts_with($content->attachment_path, 'http') ? $content->attachment_path : Storage::disk('public')->url($content->attachment_path)) : null);
            if ($url) {
                $type = str_starts_with($content->attachment_mime ?? '', 'video') ? 'video' : 'image';
                $mediaList[] = [
                    'type' => $type,
                    'url' => (string) $url,
                    'poster_url' => null,
                ];
            }

            return $mediaList;
        }

        // 5. Profil Utilisateur
        if ($content instanceof User) {
            if (! empty($content->avatar_url)) {
                $mediaList[] = [
                    'type' => 'image',
                    'url' => (string) $content->avatar_url,
                    'poster_url' => null,
                ];
            }

            return $mediaList;
        }

        // 6. Page Professionnelle
        if ($content instanceof Page) {
            if (! empty($content->logo_url)) {
                $mediaList[] = [
                    'type' => 'image',
                    'url' => (string) $content->logo_url,
                    'poster_url' => null,
                ];
            }
            if (! empty($content->cover_url)) {
                $mediaList[] = [
                    'type' => 'image',
                    'url' => (string) $content->cover_url,
                    'poster_url' => null,
                ];
            }

            return $mediaList;
        }

        return $mediaList;
    }
}
