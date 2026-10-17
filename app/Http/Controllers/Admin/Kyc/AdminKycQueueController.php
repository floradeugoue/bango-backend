<?php

namespace App\Http\Controllers\Admin\Kyc;

use App\Http\Controllers\Controller;
use App\Models\Kyc\KycSubmission;
use App\Services\Admin\AdminKycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $filters = $request->only(['status', 'profile_category', 'q', 'sort']);
        $perPage = $request->integer('per_page', 20);

        $submissions = $this->kycService->getQueue($filters, $perPage);

        // Transformation sécurisée : masquer les chemins de stockage des pièces
        $items = collect($submissions->items())->map(function (KycSubmission $sub) {
            return [
                'id' => $sub->id,
                'reference' => $sub->reference,
                'user' => [
                    'id' => $sub->user->id,
                    'name' => $sub->user->name,
                    'email' => $sub->user->email,
                    'phone' => $sub->user->phone,
                    'badge' => $sub->user->badge,
                    'is_verified' => (bool) $sub->user->is_verified,
                ],
                'profile_category' => $sub->profile_category,
                'status' => $sub->status->value,
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

        return response()->json([
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

        $submission = KycSubmission::where('id', $id)->orWhere('reference', $id)->firstOrFail();

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

        $submission = KycSubmission::where('id', $id)->orWhere('reference', $id)->firstOrFail();

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
}
