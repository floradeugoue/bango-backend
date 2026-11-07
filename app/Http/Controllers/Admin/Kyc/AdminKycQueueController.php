<?php

namespace App\Http\Controllers\Admin\Kyc;

use App\Http\Controllers\Controller;
use App\Models\Kyc\KycSubmission;
use App\Services\Admin\AdminKycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminKycQueueController extends Controller
{
    public function __construct(
        protected AdminKycService $kycService
    ) {}

    /**
     * A.1. File des demandes KYC avec filtres, compteurs et priorité.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'profile_category',
            'q',
            'sort',
            'badge',
            'kind',
            'city',
            'age',
            'awaiting',
            'country',
        ]);
        $perPage = $request->integer('per_page', 20);

        $submissions = $this->kycService->getQueue($filters, $perPage);

        // Transformation conforme au contrat TypeScript KycRow + compatibilité existante
        $items = collect($submissions->items())->map(function (KycSubmission $sub) {
            $user = $sub->user;

            $requestedBadge = $sub->metadata['requested_badge'] ?? $sub->profile_category;

            $badge = match ($sub->profile_category) {
                'landlord' => 'bailleur',
                'agent' => 'agent',
                'double' => 'double',
                'page' => 'page',
                default => ($user?->badge && $user->badge !== 'none') ? $user->badge : null,
            };
            if ($requestedBadge === 'double') {
                $badge = 'double';
            }

            $accountKind = ($sub->profile_category === 'page' || ($user?->account_type ?? null) === 'page') ? 'page' : 'person';
            $kind = $sub->metadata['kind'] ?? 'new';
            $status = match ($sub->status->value) {
                'supplement_requested' => 'more_info',
                'approved' => 'approved',
                'rejected' => 'rejected',
                'replaced' => 'replaced',
                default => 'pending',
            };

            $requiredDocs = match ($sub->profile_category) {
                'page' => 4,
                'agent' => 4,
                'landlord', 'bailleur' => 4,
                default => 2,
            };
            $receivedDocs = $sub->documents->count();
            $headlineOk = ($receivedDocs >= $requiredDocs) && ! empty($sub->id_number);

            return [
                'id' => (string) $sub->id,
                'reference' => $sub->reference,
                'account' => [
                    'id' => $user ? (string) $user->id : null,
                    'name' => $user?->name ?? $sub->full_name,
                    'display_name' => $user?->display_name ?? $user?->name ?? $sub->full_name,
                    'handle' => $user?->handle ?? ($user ? 'user'.$user->id : null),
                    'avatarUrl' => $user?->avatar_url,
                    'avatar_url' => $user?->avatar_url,
                    'city' => $user?->city,
                ],
                'accountKind' => $accountKind,
                'badge' => $badge,
                'requested_badge' => $requestedBadge,
                'kind' => $kind,
                'city' => $user?->city,
                'country' => $user?->country_code,
                'submittedAt' => $sub->created_at->toISOString(),
                'attempt' => 1,
                'docs' => [
                    'received' => $receivedDocs,
                    'required' => $requiredDocs,
                ],
                'headline' => [
                    'key' => $headlineOk ? 'id_valid' : 'docs_missing',
                    'ok' => $headlineOk,
                    'vars' => ['city' => $user?->city],
                ],
                'alerts' => 0,
                'subscription' => null,
                'refusedAt' => null,
                'propertyArea' => null,
                'managerName' => null,
                'status' => $status,
                'escalatedTo' => $sub->escalatedTo ? [
                    'id' => (string) $sub->escalatedTo->id,
                    'name' => $sub->escalatedTo->name,
                    'title' => 'Vérification',
                    'city' => $sub->escalatedTo->city,
                ] : null,
                'area' => $user?->city,
                'badgeExpiresAt' => $sub->expires_on?->toISOString(),
                'requestedDoc' => null,

                // Legacy fields for backward compatibility
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'display_name' => $user->display_name ?? $user->name,
                    'avatar_url' => $user->avatar_url,
                    'city' => $user->city,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'badge' => $user->badge,
                    'is_verified' => (bool) $user->is_verified,
                ] : null,
                'profile_category' => $sub->profile_category,
                'full_name' => $sub->full_name,
                'id_type' => $sub->id_type,
                'id_number' => $sub->id_number,
                'documents_count' => $receivedDocs,
                'submitted_at' => $sub->created_at->toISOString(),
                'expires_on' => $sub->expires_on?->toDateString(),
                'decided_at' => $sub->decided_at?->toISOString(),
                'decided_by' => $sub->decidedBy ? [
                    'id' => $sub->decidedBy->id,
                    'name' => $sub->decidedBy->name,
                ] : null,
                'escalated_to' => $sub->escalatedTo ? [
                    'id' => $sub->escalatedTo->id,
                    'name' => $sub->escalatedTo->name,
                ] : null,
            ];
        });

        $pendingQuery = KycSubmission::whereHas('user')->whereIn('status', ['pending', 'supplement_requested']);

        $toVerifyCount = (clone $pendingQuery)->count();
        $activeCount = KycSubmission::whereHas('user')->where('status', 'approved')->count();
        $expiringCount = KycSubmission::whereHas('user')->where('status', 'approved')->where('expires_on', '<=', now()->addDays(30))->count();
        $refusalsCount = KycSubmission::whereHas('user')->where('status', 'rejected')->count();
        $awaitingCount = KycSubmission::whereHas('user')->where('status', 'supplement_requested')->count();

        // 1. Facettes - Badges
        $badgeCounts = (clone $pendingQuery)
            ->selectRaw("CASE 
                WHEN profile_category IN ('landlord', 'bailleur') THEN 'bailleur'
                WHEN profile_category = 'agent' THEN 'agent'
                WHEN profile_category = 'page' THEN 'page'
                WHEN profile_category = 'double' THEN 'double'
                ELSE 'other' END as cat, count(*) as cnt")
            ->groupBy('cat')
            ->pluck('cnt', 'cat');

        $facetBadges = [
            ['value' => 'bailleur', 'count' => (int) ($badgeCounts['bailleur'] ?? 0)],
            ['value' => 'agent', 'count' => (int) ($badgeCounts['agent'] ?? 0)],
            ['value' => 'page', 'count' => (int) ($badgeCounts['page'] ?? 0)],
        ];
        if (isset($badgeCounts['double'])) {
            $facetBadges[] = ['value' => 'double', 'count' => (int) $badgeCounts['double']];
        }

        // 2. Facettes - Kinds
        $kindCounts = (clone $pendingQuery)
            ->get(['metadata'])
            ->map(fn ($s) => $s->metadata['kind'] ?? 'new')
            ->countBy();

        $facetKinds = [
            ['value' => 'new', 'count' => (int) ($kindCounts['new'] ?? 0)],
            ['value' => 'renewal', 'count' => (int) ($kindCounts['renewal'] ?? 0)],
            ['value' => 'add_property', 'count' => (int) ($kindCounts['add_property'] ?? 0)],
            ['value' => 'appeal', 'count' => (int) ($kindCounts['appeal'] ?? 0)],
        ];

        // 3. Facettes - Villes réelles des demandeurs
        $facetCities = (clone $pendingQuery)
            ->with('user:id,city')
            ->get(['id', 'user_id'])
            ->pluck('user.city')
            ->filter(fn ($city) => ! empty($city))
            ->countBy()
            ->map(fn ($cnt, $city) => ['value' => (string) $city, 'count' => (int) $cnt])
            ->values()
            ->all();

        // 4. Facettes - Anciennetés réelles & délais SLA
        $now = now();
        $sixHoursAgo = $now->copy()->subHours(6);
        $twentyFourHoursAgo = $now->copy()->subHours(24);
        $eighteenHoursAgo = $now->copy()->subHours(18);

        $lt6h = (clone $pendingQuery)->where('kyc_submissions.created_at', '>=', $sixHoursAgo)->count();
        $sixTo24h = (clone $pendingQuery)
            ->where('kyc_submissions.created_at', '<', $sixHoursAgo)
            ->where('kyc_submissions.created_at', '>=', $twentyFourHoursAgo)
            ->count();
        $gt24h = (clone $pendingQuery)->where('kyc_submissions.created_at', '<', $twentyFourHoursAgo)->count();

        $facetAges = [
            ['value' => 'lt6h', 'count' => $lt6h],
            ['value' => '6to24h', 'count' => $sixTo24h],
            ['value' => 'gt24h', 'count' => $gt24h],
        ];

        $overdue = $gt24h;

        // Near limit (entre 18h et 24h d'ancienneté)
        $nearLimit = (clone $pendingQuery)
            ->where('kyc_submissions.created_at', '<', $eighteenHoursAgo)
            ->where('kyc_submissions.created_at', '>=', $twentyFourHoursAgo)
            ->pluck('reference')
            ->all();

        // Calcul dynamique de la médiane SLA en heures
        $durations = KycSubmission::whereNotNull('decided_at')
            ->latest('decided_at')
            ->limit(50)
            ->get(['created_at', 'decided_at'])
            ->map(fn ($s) => $s->created_at->diffInRealMinutes($s->decided_at) / 60)
            ->sort()
            ->values();

        if ($durations->isEmpty()) {
            $durations = (clone $pendingQuery)
                ->limit(50)
                ->get(['created_at'])
                ->map(fn ($s) => $s->created_at->diffInRealMinutes($now) / 60)
                ->sort()
                ->values();
        }

        $medianHours = 0.0;
        if ($durations->isNotEmpty()) {
            $mid = (int) floor($durations->count() / 2);
            $medianHours = round($durations->count() % 2 === 0
                ? ($durations[$mid - 1] + $durations[$mid]) / 2
                : $durations[$mid], 1);
        }

        return response()->json([
            // Contract Page<T> format
            'items' => $items,
            'page' => $submissions->currentPage(),
            'perPage' => $submissions->perPage(),
            'total' => $submissions->total(),
            'pageCount' => $submissions->lastPage(),

            // Contract KycListResult format
            'counts' => [
                'to_verify' => $toVerifyCount,
                'active' => $activeCount,
                'expiring' => $expiringCount,
                'refusals' => $refusalsCount,
                'awaiting' => $awaitingCount,
            ],
            'facets' => [
                'badges' => $facetBadges,
                'kinds' => $facetKinds,
                'cities' => $facetCities,
                'ages' => $facetAges,
            ],
            'nearLimit' => $nearLimit,
            'overdue' => $overdue,
            'targetHours' => 24,
            'medianHours' => $medianHours,

            // Legacy keys for backward compatibility
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'per_page' => $submissions->perPage(),
                'total' => $submissions->total(),
                'last_page' => $submissions->lastPage(),
            ],
        ]);
    }

    public function requests(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    /**
     * A.4. Détail d'un dossier KYC sans exposer d'URL privée.
     */
    public function show(string $id): JsonResponse
    {
        $sub = $this->kycService->getSubmissionDetail($id);

        $documents = $sub->documents->map(function ($doc) use ($sub) {
            return [
                'id' => $doc->id,
                'kind' => $doc->kind->value,
                'original_name' => $doc->original_name,
                'mime_type' => $doc->mime_type,
                'file_size' => $doc->file_size,
                'status' => $doc->status,
                'open_url' => route('api.admin.kyc.requests.documents.open', [
                    'id' => $sub->reference ?? $sub->id,
                    'docId' => $doc->id,
                ]),
            ];
        });

        $user = $sub->user;

        // Position dans la file active
        $queueItems = KycSubmission::whereHas('user')
            ->whereIn('status', ['pending', 'supplement_requested'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 1 WHEN status = 'supplement_requested' THEN 2 ELSE 3 END ASC")
            ->orderBy('created_at', 'asc')
            ->select(['id', 'reference'])
            ->get();

        $totalQueue = $queueItems->count();
        $currentIdx = $queueItems->search(fn ($item) => (string) $item->id === (string) $sub->id || $item->reference === $sub->reference);

        if ($currentIdx !== false) {
            $index = $currentIdx + 1;
            $prevItem = $currentIdx > 0 ? $queueItems->get($currentIdx - 1) : null;
            $nextItem = $currentIdx < ($totalQueue - 1) ? $queueItems->get($currentIdx + 1) : null;

            $prev = $prevItem ? ($prevItem->reference ?? (string) $prevItem->id) : null;
            $next = $nextItem ? ($nextItem->reference ?? (string) $nextItem->id) : null;
        } else {
            $index = null;
            $prev = null;
            $next = $totalQueue > 0 ? ($queueItems->first()->reference ?? (string) $queueItems->first()->id) : null;
        }

        $position = [
            'index' => $index,
            'total' => $totalQueue,
            'prev' => $prev,
            'next' => $next,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $sub->id,
                'reference' => $sub->reference,
                'account' => [
                    'id' => $user ? (string) $user->id : null,
                    'name' => $user?->name ?? $sub->full_name,
                    'display_name' => $user?->display_name ?? $user?->name ?? $sub->full_name,
                    'handle' => $user?->handle ?? ($user ? 'user'.$user->id : null),
                    'avatar_url' => $user?->avatar_url,
                    'avatarUrl' => $user?->avatar_url,
                    'city' => $user?->city,
                    'badge' => $user?->badge,
                    'is_verified' => (bool) $user?->is_verified,
                ],
                'user' => [
                    'id' => $sub->user->id,
                    'name' => $sub->user->name,
                    'display_name' => $sub->user->display_name ?? $sub->user->name,
                    'email' => $sub->user->email,
                    'phone' => $sub->user->phone,
                    'avatar_url' => $sub->user->avatar_url,
                    'city' => $sub->user->city,
                    'badge' => $sub->user->badge,
                    'is_verified' => (bool) $sub->user->is_verified,
                    'verification_lapsed' => (bool) $sub->user->verification_lapsed,
                    'active_subscriptions_count' => $sub->user->subscriptions->count(),
                ],
                'position' => $position,
                'profile_category' => $sub->profile_category,
                'status' => $sub->status->value,
                'full_name' => $sub->full_name,
                'id_type' => $sub->id_type,
                'id_number' => $sub->id_number,
                'property_right' => $sub->property_right,
                'deed_type' => $sub->deed_type,
                'rejection_reason' => $sub->rejection_reason,
                'face_match' => $sub->metadata['face_match'] ?? null,
                'liveness' => $sub->metadata['liveness'] ?? null,
                'poses' => [
                    'selfie' => $documents->firstWhere('kind', 'selfie'),
                    'liveness_frames' => $documents->where('kind', 'liveness_frame')->values(),
                ],
                'documents' => $documents,
                'submitted_at' => $sub->created_at->toISOString(),
                'expires_on' => $sub->expires_on?->toDateString(),
                'decided_at' => $sub->decided_at?->toISOString(),
                'decided_by' => $sub->decidedBy ? [
                    'id' => $sub->decidedBy->id,
                    'name' => $sub->decidedBy->name,
                ] : null,
                'escalated_to' => $sub->escalatedTo ? [
                    'id' => $sub->escalatedTo->id,
                    'name' => $sub->escalatedTo->name,
                ] : null,
                'escalated_by' => $sub->escalatedBy ? [
                    'id' => $sub->escalatedBy->id,
                    'name' => $sub->escalatedBy->name,
                ] : null,
                'escalation_reason' => $sub->escalation_reason,
                'escalated_at' => $sub->escalated_at?->toISOString(),
                'access_logs' => $sub->accessLogs->map(function ($log) {
                    return [
                        'operator_id' => $log->operator_id,
                        'operator_name' => $log->operator?->name,
                        'action' => $log->action,
                        'created_at' => $log->created_at->toISOString(),
                    ];
                }),
            ],
        ]);
    }

    /**
     * A.5. Prise de décision d'instruction (approve, reject, request_changes).
     */
    public function decision(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject,request_changes,supplement_requested'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $submission = $this->findSubmission($id);

        try {
            $updated = $this->kycService->decide(
                submission: $submission,
                operator: $request->user(),
                decision: $validated['decision'],
                reason: $validated['reason'] ?? null
            );
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'KYC_ALREADY_DECIDED',
                'errors' => ['decision' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Décision KYC enregistrée avec succès.',
            'data' => [
                'id' => $updated->id,
                'reference' => $updated->reference,
                'status' => $updated->status->value,
                'decided_at' => $updated->decided_at?->toISOString(),
                'expires_on' => $updated->expires_on?->toDateString(),
                'rejection_reason' => $updated->rejection_reason,
                'user_badge' => $updated->user->badge,
                'user_is_verified' => (bool) $updated->user->is_verified,
            ],
        ]);
    }

    /**
     * A.6. Escalade hiérarchique d'un dossier.
     */
    public function escalate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $submission = $this->findSubmission($id);

        $updated = $this->kycService->escalate(
            submission: $submission,
            operator: $request->user(),
            targetUserId: $validated['target_user_id'],
            reason: $validated['reason']
        );

        return response()->json([
            'success' => true,
            'message' => 'Dossier KYC escaladé avec succès.',
            'data' => [
                'id' => $updated->id,
                'reference' => $updated->reference,
                'escalated_to' => [
                    'id' => $updated->escalatedTo->id,
                    'name' => $updated->escalatedTo->name,
                ],
                'escalation_reason' => $updated->escalation_reason,
                'escalated_at' => $updated->escalated_at?->toISOString(),
            ],
        ]);
    }

    /**
     * A.6 bis. Destinataires habilités à recevoir l'escalade hiérarchique.
     */
    public function escalationTargets(Request $request): JsonResponse
    {
        $targets = $this->kycService->getEscalationTargets($request->user());

        return response()->json([
            'success' => true,
            'data' => $targets->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->name ?? 'Direction',
                ];
            }),
        ]);
    }

    /**
     * A.8. Règles officielles de vérification d'identité.
     */
    public function rules(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->kycService->getRules(),
        ]);
    }

    /**
     * Recherche sécurisée d'un dossier KYC par UUID ou référence métier.
     */
    protected function findSubmission(string $idOrRef): KycSubmission
    {
        return Str::isUuid($idOrRef)
            ? KycSubmission::where(fn ($q) => $q->where('id', $idOrRef)->orWhere('reference', $idOrRef))->firstOrFail()
            : KycSubmission::where('reference', $idOrRef)->firstOrFail();
    }
}
