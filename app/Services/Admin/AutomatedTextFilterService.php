<?php

namespace App\Services\Admin;

use App\Enums\Listing\ListingStatus;
use App\Enums\Support\AppealStatus;
use App\Models\Listing\Listing;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationFilterLog;
use App\Models\Support\Report;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use App\Notifications\Support\ModerationDecisionNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutomatedTextFilterService
{
    /**
     * Motifs d'expression régulière pour la détection automatique de contenus suspects.
     * Recouvre les catégories de l'application : scam, off_platform, spam, offensive.
     */
    protected const PATTERNS = [
        'scam' => '/(avance\s*obligatoire|frais\s*de\s*visite\s*avant|virement\s*avant\s*visite|envoyez\s*d[\'\s]*abord|payer\s*avant\s*de\s*voir|gain\s*de\s*loterie|doubler?\s*vos\s*gains|doublez?\s*votre\s*mise|investissement\s*miracle|investis?\s*et\s*recois|cliquez?\s*ici\s*pour\s*gagner|mot\s*de\s*passe\s*bancaire|code\s*secret\s*momo|arnaque\s*garantie|argent\s*facile\s*sans\s*effort)/i',
        'off_platform' => '/(whatsapp|wa\.me|telegram|t\.me|monetbil\s*direct|orange\s*money\s*direct|mtn\s*momo\s*direct|western\s*union|moneygram|virement\s*direct|envoi\s*cash|contactez?[- ]moi\s*sur\s*whatsapp|whatsapp\s*au\s*:\s*[0-9]+|\b(?:6[5-9]\d[\s.\-]*\d{2}[\s.\-]*\d{2}[\s.\-]*\d{2})\b|(\+?237|00237|\+?225|00225|\+?221|00221)?\s*[62307]\s*[0-9](\s*[0-9]){7})/i',
        'spam' => '/(bit\.ly\/[a-z0-9]+|tinyurl\.com\/[a-z0-9]+|promo\s*flash\s*100%|promo\s*exceptionnelle|cliquez?\s*ici|gagnez?\s*de\s*l[\'\s]*argent|cliquez?\s*sur\s*mon\s*lien\s*en\s*bio|gagnez?\s*des\s*abonn[eé]s|pub\s*gratuite\s*ici|(?:https?:\/\/\S+.*){2,}|(.)\1{9,})/i',
        'offensive' => '/\b(sale\s*race|va\s*crever|je\s*vais\s*te\s*tuer|mort\s*aux|bande\s*de\s*chiens|escroc\s*de\s*merde|batard|b[aâ]tard|connard|salaud|imb[eé]cile)\b/i',
    ];

    /**
     * Libellés explicites des règles du filtre automatique pour les notifications et sanctions.
     */
    protected const RULE_LABELS = [
        'scam' => 'Arnaque ou tentative de fraude',
        'off_platform' => 'Contournement de la plateforme ou partage de coordonnées',
        'spam' => 'Spam ou publicité non sollicitée',
        'offensive' => 'Propos offensants ou inappropriés',
    ];

    /**
     * Analyse un texte, applique l'échelle de sanctions sur 90 jours et enregistre l'événement de filtre.
     * Échelle : 1re fois = warned ; 2e = removed (content_removal) ; 3e+ en 90 jours = restricted 7j (restriction + retrait).
     *
     * @return array<string, mixed>|null
     */
    public function scanText(string $text, string $contentType, string|int $contentId, ?User $author = null): ?array
    {
        $detectedCategory = null;
        $matchedPattern = null;

        foreach (self::PATTERNS as $category => $regex) {
            if (preg_match($regex, $text, $matches)) {
                $detectedCategory = $category;
                $matchedPattern = trim((string) $matches[0]);
                break;
            }
        }

        if (! $detectedCategory) {
            return null;
        }

        if (! $author) {
            $author = $this->resolveAuthorFromContent($contentType, $contentId);
        }

        return DB::transaction(function () use ($text, $contentType, $contentId, $author, $detectedCategory, $matchedPattern) {
            $priorCount = 0;
            if ($author) {
                $priorCount = ModerationFilterLog::where('user_id', $author->id)
                    ->where('created_at', '>=', now()->subDays(90))
                    ->count();
            }

            $step = min(3, $priorCount + 1);
            $actionTaken = match ($step) {
                1 => 'warned',
                2 => 'removed',
                default => 'restricted',
            };

            $restrictedUntil = $step >= 3 ? now()->addDays(7) : null;
            $sanction = null;

            $ruleLabel = self::RULE_LABELS[$detectedCategory] ?? 'Infraction détectée par le filtre automatique';
            $justification = match ($step) {
                1 => "Avertissement automatique : votre contenu comporte un élément non conforme ({$ruleLabel}).",
                2 => "Retrait automatique (2e alerte en 90 jours) : {$ruleLabel}.",
                default => "Retrait automatique et restriction de compte de 7 jours (3e alerte en 90 jours) : {$ruleLabel}.",
            };

            if ($step >= 2) {
                $this->removeFlaggedContent($contentType, $contentId, $justification);
            }

            if ($author) {
                if ($step === 1) {
                    try {
                        $author->notify(new ModerationDecisionNotification(null, 'warn_author', $justification));
                    } catch (\Throwable $e) {
                        Log::warning('Échec notification avertissement filtre automatique : '.$e->getMessage());
                    }
                } elseif ($step === 2) {
                    $sanction = UserAccountSanction::create([
                        'user_id' => $author->id,
                        'operator_id' => null,
                        'type' => 'content_removal',
                        'level' => 2,
                        'starts_at' => now(),
                        'reason' => $justification,
                        'is_active' => true,
                        'metadata' => [
                            'source' => 'automated_filter',
                            'target_type' => $contentType,
                            'target_id' => (string) $contentId,
                            'quote' => $text,
                            'where' => $contentType,
                            'rule' => $detectedCategory,
                            'rule_body' => $ruleLabel,
                            'step' => 2,
                        ],
                    ]);

                    try {
                        $author->notify(new ModerationDecisionNotification(null, 'remove', $justification));
                    } catch (\Throwable $e) {
                        Log::warning('Échec notification retrait filtre automatique : '.$e->getMessage());
                    }
                } else {
                    $author->restricted_until = $restrictedUntil;
                    $author->save();

                    $sanction = UserAccountSanction::create([
                        'user_id' => $author->id,
                        'operator_id' => null,
                        'type' => 'restriction',
                        'level' => 3,
                        'duration_days' => 7,
                        'starts_at' => now(),
                        'ends_at' => $restrictedUntil,
                        'reason' => $justification,
                        'is_active' => true,
                        'metadata' => [
                            'source' => 'automated_filter',
                            'target_type' => $contentType,
                            'target_id' => (string) $contentId,
                            'restricted_until' => $restrictedUntil?->toIso8601String(),
                            'quote' => $text,
                            'where' => $contentType,
                            'rule' => $detectedCategory,
                            'rule_body' => $ruleLabel,
                            'step' => 3,
                        ],
                    ]);

                    try {
                        $author->notify(new ModerationDecisionNotification(null, 'restriction', $justification, $restrictedUntil));
                    } catch (\Throwable $e) {
                        Log::warning('Échec notification restriction filtre automatique : '.$e->getMessage());
                    }
                }
            }

            $log = ModerationFilterLog::create([
                'user_id' => $author?->id,
                'content_type' => $contentType,
                'content_id' => (string) $contentId,
                'category' => $detectedCategory,
                'detected_pattern' => $matchedPattern,
                'original_text' => $text,
                'action_taken' => $actionTaken,
                'step' => $step,
                'restricted_until' => $restrictedUntil,
                'sanction_id' => $sanction?->id,
                'created_at' => now(),
            ]);

            if ($sanction) {
                $meta = is_array($sanction->metadata) ? $sanction->metadata : [];
                $meta['filter_log_id'] = $log->id;
                $sanction->metadata = $meta;
                $sanction->save();
            }

            return [
                'flagged' => true,
                'category' => $detectedCategory,
                'pattern' => $matchedPattern,
                'action_taken' => $actionTaken,
                'step' => $step,
                'restricted_until' => $restrictedUntil?->toIso8601String(),
                'sanction_id' => $sanction?->id,
                'log_id' => $log->id,
            ];
        });
    }

    /**
     * Récupère la liste paginée des déclenchements du filtre textuel automatique.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getFilterLogs(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ModerationFilterLog::query()->with([
            'user:id,name,display_name,handle,email,avatar_url,city,restricted_until',
            'sanction',
        ]);

        // 1. Filtre category / category[] (et prise en charge de l'ancien alias phone_leak sous off_platform)
        if (! empty($filters['category'])) {
            $categories = is_array($filters['category'])
                ? $filters['category']
                : array_map('trim', explode(',', (string) $filters['category']));
            $categories = array_values(array_filter($categories));

            if (in_array('off_platform', $categories, true) && ! in_array('phone_leak', $categories, true)) {
                $categories[] = 'phone_leak';
            }

            if (! empty($categories)) {
                $query->whereIn('category', $categories);
            }
        }

        // 2. Filtre step / step[] (1, 2, 3)
        if (! empty($filters['step'])) {
            $steps = is_array($filters['step'])
                ? $filters['step']
                : explode(',', (string) $filters['step']);
            $steps = array_values(array_filter(array_map('intval', $steps), fn (int $s) => in_array($s, [1, 2, 3], true)));

            if (! empty($steps)) {
                $query->whereIn('step', $steps);
            }
        }

        // 3. Filtre action_taken / action_taken[]
        if (! empty($filters['action_taken'])) {
            $actions = is_array($filters['action_taken'])
                ? $filters['action_taken']
                : array_map('trim', explode(',', (string) $filters['action_taken']));
            $actions = array_values(array_filter($actions));

            if (! empty($actions)) {
                $query->whereIn('action_taken', $actions);
            }
        }

        // 4. Filtre appealed=1 (uniquement les déclenchements ayant fait l'objet d'un recours)
        if (! empty($filters['appealed']) && filter_var($filters['appealed'], FILTER_VALIDATE_BOOLEAN)) {
            $appealedFilterIds = $this->resolveAppealedFilterLogIds();
            if (empty($appealedFilterIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $appealedFilterIds);
            }
        }

        // 5. Filtres de dates
        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['date_from'])->toDateString());
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['date_to'])->toDateString());
        }

        // 6. Recherche plein texte
        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('detected_pattern', 'LIKE', "%{$search}%")
                    ->orWhere('original_text', 'LIKE', "%{$search}%")
                    ->orWhere('content_id', 'LIKE', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('display_name', 'LIKE', "%{$search}%")
                            ->orWhere('handle', 'LIKE', "%{$search}%")
                            ->orWhere('city', 'LIKE', "%{$search}%");
                    });
            });
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        // Enrichissement de chaque ligne : step, restricted_until, appeal: { id, status }, author: { id, display_name, city }
        $this->enrichFilterLogCollection($paginator->getCollection());

        return $paginator;
    }

    /**
     * Calcule les indicateurs agrégés (meta) sur les 30 derniers jours.
     *
     * @return array<string, mixed>
     */
    public function getFilterMeta(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $logs30d = ModerationFilterLog::query()
            ->where('created_at', '>=', $since)
            ->get(['id', 'user_id', 'category', 'action_taken', 'step', 'restricted_until', 'sanction_id', 'created_at']);

        $step1Count = 0;
        $step2Count = 0;
        $step3Count = 0;
        $warnedCount = 0;
        $removedCount = 0;
        $restrictedCount = 0;

        $byCategory = [
            'scam' => 0,
            'off_platform' => 0,
            'spam' => 0,
            'offensive' => 0,
        ];

        $dailyBuckets = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dateStr = now()->subDays($i)->toDateString();
            $dailyBuckets[$dateStr] = [
                'date' => $dateStr,
                'warned' => 0,
                'removed' => 0,
            ];
        }

        foreach ($logs30d as $log) {
            $step = (int) ($log->step ?: match ($log->action_taken) {
                'removed' => 2,
                'restricted' => 3,
                default => 1,
            });

            if ($step === 1) {
                $step1Count++;
            } elseif ($step === 2) {
                $step2Count++;
            } else {
                $step3Count++;
            }

            if (in_array($log->action_taken, ['warned', 'flagged'], true) || $step === 1) {
                $warnedCount++;
            }
            if (in_array($log->action_taken, ['removed', 'restricted'], true) || $step >= 2) {
                $removedCount++;
            }
            if ($log->action_taken === 'restricted' || $step >= 3) {
                $restrictedCount++;
            }

            $cat = $log->category === 'phone_leak' ? 'off_platform' : $log->category;
            if (isset($byCategory[$cat])) {
                $byCategory[$cat]++;
            }

            $dateKey = $log->created_at?->toDateString();
            if ($dateKey && isset($dailyBuckets[$dateKey])) {
                if ($step === 1) {
                    $dailyBuckets[$dateKey]['warned']++;
                } else {
                    $dailyBuckets[$dateKey]['removed']++;
                }
            }
        }

        // Restrictions actives actuellement (en cours de validité)
        $activeRestrictions = User::whereNotNull('restricted_until')
            ->where('restricted_until', '>', now())
            ->count();

        // Taux de recours acceptés sur 30 jours (recours rattachés aux sanctions du filtre ou aux logs de filtre)
        $appealsMap = $this->findAppealsForLogs($logs30d);
        $totalAppeals = count($appealsMap);
        $acceptedAppeals = 0;

        foreach ($appealsMap as $appeal) {
            $statusVal = $appeal->status instanceof AppealStatus ? $appeal->status->value : (string) $appeal->status;
            if ($statusVal === AppealStatus::Approved->value || $appeal->resolution === 'revoked') {
                $acceptedAppeals++;
            }
        }

        $appealAcceptanceRate = $totalAppeals > 0
            ? round(($acceptedAppeals / $totalAppeals) * 100, 1)
            : 0.0;

        return [
            'window_days' => $days,
            'total' => $logs30d->count(),
            'warned' => $warnedCount,
            'removed' => $removedCount,
            'restricted' => $restrictedCount,
            'active_restrictions' => $activeRestrictions,
            'by_step' => [
                '1' => $step1Count,
                '2' => $step2Count,
                '3' => $step3Count,
                'warned' => $step1Count,
                'removed' => $step2Count,
                'restricted' => $step3Count,
            ],
            'by_category' => $byCategory,
            'appeals_total' => $totalAppeals,
            'appeals_accepted' => $acceptedAppeals,
            'appeal_acceptance_rate' => $appealAcceptanceRate,
            'overturn_rate' => $appealAcceptanceRate,
            'accepted_appeals_rate' => $appealAcceptanceRate,
            'daily' => array_values($dailyBuckets),
        ];
    }

    /**
     * Enrichit chaque ligne de ModerationFilterLog avec author, step, restricted_until et appeal.
     *
     * @param  Collection<int, ModerationFilterLog>  $logs
     */
    protected function enrichFilterLogCollection(Collection $logs): void
    {
        if ($logs->isEmpty()) {
            return;
        }

        $appealsByLogId = $this->findAppealsForLogs($logs);

        foreach ($logs as $log) {
            // Normalisation de la catégorie historique phone_leak -> off_platform
            if ($log->category === 'phone_leak') {
                $log->category = 'off_platform';
            }

            $step = (int) ($log->step ?: match ($log->action_taken) {
                'removed' => 2,
                'restricted' => 3,
                default => 1,
            });
            $log->setAttribute('step', $step);

            if ($log->action_taken === 'flagged') {
                $log->setAttribute('action_taken', 'warned');
            }

            $user = $log->user;
            $restrictedUntil = $log->restricted_until
                ?? $log->sanction?->ends_at
                ?? ($step >= 3 ? $user?->restricted_until : null);

            $log->setAttribute('restricted_until', $restrictedUntil instanceof \DateTimeInterface
                ? Carbon::instance($restrictedUntil)->toIso8601String()
                : ($restrictedUntil ? Carbon::parse((string) $restrictedUntil)->toIso8601String() : null));

            $log->setAttribute('author', $user ? [
                'id' => $user->id,
                'display_name' => $user->display_name ?: $user->name,
                'city' => $user->city,
            ] : null);

            /** @var ModerationAppeal|null $appeal */
            $appeal = $appealsByLogId[$log->id] ?? null;
            if ($appeal) {
                $statusVal = $appeal->status instanceof AppealStatus ? $appeal->status->value : (string) $appeal->status;
                $log->setAttribute('appeal', [
                    'id' => $appeal->id,
                    'status' => $statusVal,
                ]);
            } else {
                $log->setAttribute('appeal', null);
            }
        }
    }

    /**
     * Recherche les recours associés à une collection de logs du filtre automatique.
     *
     * @param  Collection<int, ModerationFilterLog>  $logs
     * @return array<int, ModerationAppeal>
     */
    protected function findAppealsForLogs(Collection $logs): array
    {
        if ($logs->isEmpty()) {
            return [];
        }

        $logIds = $logs->pluck('id')->map(fn ($id) => (string) $id)->all();
        $sanctionIds = $logs->pluck('sanction_id')->filter()->map(fn ($id) => (string) $id)->all();
        $refs = array_values(array_unique(array_merge($logIds, $sanctionIds)));

        $userIds = $logs->pluck('user_id')->filter()->unique()->values()->all();

        $appeals = ModerationAppeal::query()
            ->where(function ($q) use ($refs, $userIds) {
                if (! empty($refs)) {
                    $q->whereIn('sanction_reference', $refs);
                }
                if (! empty($userIds)) {
                    $q->orWhere(function ($sub) use ($userIds) {
                        $sub->whereIn('user_id', $userIds)
                            ->whereIn('sanction_type', ['content_removal', 'restriction', 'warning']);
                    });
                }
            })
            ->orderByDesc('id')
            ->get();

        if ($appeals->isEmpty()) {
            return [];
        }

        $bySanctionId = [];
        $byFilterLogRef = [];
        $byUserAndTypeWithoutRef = [];

        $knownSanctionIds = ModerationFilterLog::whereNotNull('sanction_id')
            ->pluck('sanction_id')
            ->map(fn ($id) => (string) $id)
            ->flip()
            ->all();

        foreach ($appeals as $appeal) {
            $ref = trim((string) ($appeal->sanction_reference ?? ''));
            if ($ref !== '') {
                if (str_starts_with(strtoupper($ref), 'FLT-')) {
                    $byFilterLogRef[substr($ref, 4)] = $appeal;
                } elseif (isset($knownSanctionIds[$ref])) {
                    $bySanctionId[$ref] = $appeal;
                } else {
                    $byFilterLogRef[$ref] = $appeal;
                }
            } else {
                $byUserAndTypeWithoutRef[$appeal->user_id.':'.$appeal->sanction_type] = $appeal;
            }
        }

        $result = [];
        foreach ($logs as $log) {
            $matched = null;
            if ($log->sanction_id && isset($bySanctionId[(string) $log->sanction_id])) {
                $matched = $bySanctionId[(string) $log->sanction_id];
            } elseif (isset($byFilterLogRef[(string) $log->id])) {
                $candidate = $byFilterLogRef[(string) $log->id];
                if (! $log->user_id || (int) $candidate->user_id === (int) $log->user_id) {
                    $matched = $candidate;
                }
            } elseif ($log->user_id && $log->sanction_id) {
                $expectedType = ((int) $log->step >= 3 || $log->action_taken === 'restricted') ? 'restriction' : 'content_removal';
                $matched = $byUserAndTypeWithoutRef[$log->user_id.':'.$expectedType] ?? null;
            }

            if ($matched) {
                $result[$log->id] = $matched;
            }
        }

        return $result;
    }

    /**
     * Retourne les identifiants des logs de filtre qui disposent d'un recours associé.
     *
     * @return array<int>
     */
    protected function resolveAppealedFilterLogIds(): array
    {
        $candidateLogs = ModerationFilterLog::query()
            ->get(['id', 'user_id', 'step', 'action_taken', 'sanction_id']);

        return array_keys($this->findAppealsForLogs($candidateLogs));
    }

    /**
     * Retire ou masque le contenu signalé aux paliers 2 et 3.
     */
    protected function removeFlaggedContent(string $contentType, string|int $contentId, string $justification): void
    {
        $content = $this->findContentModel($contentType, $contentId);
        if (! $content || $content instanceof User) {
            return;
        }

        if ($content instanceof Listing) {
            $content->status = ListingStatus::Draft;
            $content->rejection_reason = $justification;
            $content->withdrawn_by_bango = true;
            $content->save();

            return;
        }

        if (method_exists($content, 'delete')) {
            $content->delete();

            return;
        }

        if (isset($content->is_archived)) {
            $content->is_archived = true;
            $content->save();
        }
    }

    /**
     * Résout l'auteur d'un contenu si non fourni directement à scanText.
     */
    protected function resolveAuthorFromContent(string $contentType, string|int $contentId): ?User
    {
        $content = $this->findContentModel($contentType, $contentId);
        if (! $content) {
            return null;
        }

        if ($content instanceof User) {
            return $content;
        }

        $authorId = $content->user_id ?? $content->owner_id ?? $content->sender_id ?? null;

        return $authorId ? User::find($authorId) : null;
    }

    /**
     * Charge le modèle Eloquent correspondant au type et à l'identifiant du contenu.
     */
    protected function findContentModel(string $contentType, string|int $contentId): mixed
    {
        $normalized = strtolower(trim($contentType));

        $class = match ($normalized) {
            'post', 'posts' => Post::class,
            'comment', 'comments', 'post_comment', 'post_comments' => PostComment::class,
            'reel', 'reels' => Reel::class,
            'reel_comment', 'reel_comments' => ReelComment::class,
            'listing', 'listings' => Listing::class,
            'message', 'messages' => Message::class,
            'page', 'pages' => Page::class,
            'user', 'users', 'account', 'profile' => User::class,
            default => Report::resolveReportableClass($contentType),
        };

        if (! $class || ! class_exists($class)) {
            return null;
        }

        return $class::find($contentId);
    }
}
