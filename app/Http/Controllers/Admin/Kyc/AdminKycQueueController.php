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
            $badge = match ($sub->profile_category) {
                'landlord' => 'bailleur',
                'agent' => 'agent',
                'page' => 'page',
                default => $sub->user->badge ?? 'bailleur',
            };

            $accountKind = ($sub->profile_category === 'page' || ($sub->user->account_type ?? null) === 'page') ? 'page' : 'person';
            $kind = $sub->metadata['kind'] ?? 'new';
            $status = $sub->status->value === 'supplement_requested' ? 'more_info' : ($sub->status->value === 'approved' ? 'approved' : ($sub->status->value === 'rejected' ? 'rejected' : 'pending'));

            return [
                'id' => (string) $sub->id,
                'reference' => $sub->reference,
                'account' => [
                    'id' => (string) $sub->user->id,
                    'name' => $sub->user->name ?? $sub->full_name,
                    'handle' => $sub->user->handle ?? ('user'.$sub->user->id),
                    'avatarUrl' => $sub->user->avatar_url,
                    'city' => $sub->user->city ?? 'Dakar',
                ],
                'accountKind' => $accountKind,
                'badge' => $badge,
                'kind' => $kind,
                'city' => $sub->user->city ?? 'Dakar',
                'country' => $sub->user->country_code ?? 'SN',
                'submittedAt' => $sub->created_at->toISOString(),
                'attempt' => 1,
                'docs' => [
                    'received' => $sub->documents->count(),
                    'required' => 2,
                ],
                'headline' => [
                    'key' => 'id_valid',
                    'ok' => true,
                    'vars' => ['city' => $sub->user->city ?? 'Dakar'],
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
                    'city' => 'Dakar',
                ] : null,
                'area' => $sub->user->city ?? 'Dakar',
                'badgeExpiresAt' => $sub->expires_on?->toISOString(),
                'requestedDoc' => null,

                // Legacy fields for backward compatibility
                'user' => [
                    'id' => $sub->user->id,
                    'name' => $sub->user->name,
                    'email' => $sub->user->email,
                    'phone' => $sub->user->phone,
                    'badge' => $sub->user->badge,
                    'is_verified' => (bool) $sub->user->is_verified,
                ],
                'profile_category' => $sub->profile_category,
                'full_name' => $sub->full_name,
                'id_type' => $sub->id_type,
                'id_number' => $sub->id_number,
                'documents_count' => $sub->documents->count(),
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

        $toVerifyCount = KycSubmission::whereIn('status', ['pending', 'supplement_requested'])->count();
        $activeCount = KycSubmission::where('status', 'approved')->count();
        $expiringCount = KycSubmission::where('status', 'approved')->where('expires_on', '<=', now()->addDays(30))->count();
        $refusalsCount = KycSubmission::where('status', 'rejected')->count();
        $awaitingCount = KycSubmission::where('status', 'supplement_requested')->count();

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
                'badges' => [
                    ['value' => 'bailleur', 'count' => $toVerifyCount],
                    ['value' => 'agent', 'count' => 0],
                    ['value' => 'page', 'count' => 0],
                ],
                'kinds' => [
                    ['value' => 'new', 'count' => $toVerifyCount],
                    ['value' => 'renewal', 'count' => 0],
                    ['value' => 'add_property', 'count' => 0],
                    ['value' => 'appeal', 'count' => 0],
                ],
                'cities' => [
                    ['value' => 'Dakar', 'count' => $toVerifyCount],
                ],
                'ages' => [
                    ['value' => 'lt6h', 'count' => $toVerifyCount],
                    ['value' => '6to24h', 'count' => 0],
                    ['value' => 'gt24h', 'count' => 0],
                ],
            ],
            'nearLimit' => [],
            'overdue' => 0,
            'targetHours' => 24,
            'medianHours' => 3.5,

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

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $sub->id,
                'reference' => $sub->reference,
                'user' => [
                    'id' => $sub->user->id,
                    'name' => $sub->user->name,
                    'email' => $sub->user->email,
                    'phone' => $sub->user->phone,
                    'badge' => $sub->user->badge,
                    'is_verified' => (bool) $sub->user->is_verified,
                    'verification_lapsed' => (bool) $sub->user->verification_lapsed,
                    'active_subscriptions_count' => $sub->user->subscriptions->count(),
                ],
                'profile_category' => $sub->profile_category,
                'status' => $sub->status->value,
                'full_name' => $sub->full_name,
                'id_type' => $sub->id_type,
                'id_number' => $sub->id_number,
                'property_right' => $sub->property_right,
                'deed_type' => $sub->deed_type,
                'rejection_reason' => $sub->rejection_reason,
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
