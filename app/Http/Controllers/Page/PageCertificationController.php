<?php

namespace App\Http\Controllers\Page;

use App\Http\Controllers\Controller;
use App\Http\Requests\Page\ResubmitPageCertificationRequest;
use App\Http\Requests\Page\SubmitPageCertificationRequest;
use App\Http\Resources\Page\PageCertificationResource;
use App\Http\Resources\Payment\PaymentTransactionResource;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Services\Page\PageCertificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PageCertificationController extends Controller
{
    public function __construct(
        protected PageCertificationService $certificationService
    ) {}

    /**
     * Consulte le dernier dossier de certification de la Page.
     */
    public function show(Request $request, Page $page): PageCertificationResource
    {
        $certification = $page->latestCertification;

        if (! $certification) {
            abort(404, 'Aucun dossier de certification n\'a été déposé pour cette page.');
        }

        Gate::authorize('view', $certification);

        return new PageCertificationResource($certification);
    }

    /**
     * Historique complet des dossiers de certification de la Page.
     */
    public function history(Request $request, Page $page): AnonymousResourceCollection
    {
        if (! $page->hasMember($request->user()) && ! $request->user()->isAdmin()) {
            abort(403, 'Accès non autorisé.');
        }

        $certifications = $page->certifications()
            ->with(['reviewer', 'submitter'])
            ->paginate($request->integer('per_page', 10));

        return PageCertificationResource::collection($certifications);
    }

    /**
     * Soumet un dossier de certification entreprise.
     */
    public function store(SubmitPageCertificationRequest $request, Page $page): JsonResponse
    {
        Gate::authorize('submitCertification', $page);

        $files = array_filter([
            'rccm_doc' => $request->file('rccm_doc'),
            'nif_doc' => $request->file('nif_doc'),
            'id_doc' => $request->file('id_doc'),
            'extra_doc' => $request->file('extra_doc'),
        ]);

        $certification = $this->certificationService->submit(
            page: $page,
            user: $request->user(),
            data: $request->validated(),
            files: $files
        );

        return (new PageCertificationResource($certification))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Soumet une correction au dossier (PG-07 : 2 corrections max incluses).
     */
    public function resubmit(ResubmitPageCertificationRequest $request, PageCertification $certification): PageCertificationResource
    {
        Gate::authorize('resubmit', $certification);

        $files = array_filter([
            'rccm_doc' => $request->file('rccm_doc'),
            'nif_doc' => $request->file('nif_doc'),
            'id_doc' => $request->file('id_doc'),
            'extra_doc' => $request->file('extra_doc'),
        ]);

        $updated = $this->certificationService->resubmitCorrection(
            certification: $certification,
            user: $request->user(),
            data: $request->validated(),
            files: $files
        );

        return new PageCertificationResource($updated);
    }

    /**
     * Initie le règlement des frais d'examen de certification via PAY.
     * Lance la demande Mobile Money et rattache la transaction au dossier.
     */
    public function initiatePayment(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('submitCertification', $page);

        $request->validate([
            'provider' => ['nullable', 'string', 'in:orange,mtn,moov,wave,cash'],
            'operator' => ['nullable', 'string', 'in:orange,mtn,moov,wave,cash'],
            'phone_number' => ['nullable', 'string', 'max:25'],
            'phone' => ['nullable', 'string', 'max:25'],
            'price_id' => ['nullable', 'integer', 'exists:subscription_prices,id'],
            'gateway' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'key' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:5'],
            'country_code' => ['nullable', 'string', 'max:5'],
        ]);

        $operator = $request->input('operator') ?? $request->input('provider');
        $phone = $request->input('phone') ?? $request->input('phone_number');

        if (! $operator) {
            throw ValidationException::withMessages([
                'provider' => ['L\'opérateur ou fournisseur de paiement (provider/operator) est obligatoire.'],
            ]);
        }

        if (! $phone) {
            throw ValidationException::withMessages([
                'phone_number' => ['Le numéro de téléphone (phone_number/phone) est obligatoire.'],
            ]);
        }

        $payload = array_merge($request->all(), [
            'provider' => $operator,
            'operator' => $operator,
            'phone_number' => $phone,
            'phone' => $phone,
        ]);

        $result = $this->certificationService->initiatePayment(
            page: $page,
            user: $request->user(),
            paymentData: $payload
        );

        $transaction = $result['transaction'];
        $initiateResult = $result['initiate_result'] ?? null;
        $timeoutSeconds = (int) config('payment.timeout_seconds', 120);

        if ($initiateResult && ! $initiateResult->isSuccessful) {
            return response()->json([
                'success' => false,
                'code' => $initiateResult->errorCode ?? 'GATEWAY_ERROR',
                'message' => $initiateResult->errorMessage ?? 'Échec lors de l\'initialisation chez l\'opérateur.',
                'data' => (new PaymentTransactionResource($transaction))->resolve(),
            ], 422);
        }

        return (new PaymentTransactionResource($transaction))
            ->additional([
                'success' => true,
                'message' => 'Demande de paiement envoyée sur le mobile.',
                'prompt' => [
                    'ussd' => $initiateResult?->ussdPrompt,
                    'ussd_code' => $initiateResult?->ussdPrompt,
                    'message' => $initiateResult?->ussdPrompt,
                    'timeout_seconds' => $timeoutSeconds,
                    'expires_at' => $transaction->created_at ? $transaction->created_at->addSeconds($timeoutSeconds)->toIso8601String() : now()->addSeconds($timeoutSeconds)->toIso8601String(),
                    'instruction' => 'Veuillez valider l\'opération sur votre téléphone en saisissant votre code secret Mobile Money.',
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Téléchargement sécurisé d'une pièce administrative privée.
     */
    public function downloadDocument(Request $request, PageCertification $certification, string $key): StreamedResponse
    {
        Gate::authorize('downloadDocument', $certification);

        return $this->certificationService->downloadDocument($certification, $key, $request->user());
    }

    /**
     * Annule la demande de certification en cours tant qu'elle est au statut pending (PG-06, frais non remboursés).
     * DELETE /api/pages/{page}/certification
     */
    public function destroy(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('submitCertification', $page);

        $this->certificationService->cancelPending($page, $request->user());

        return response()->json([
            'message' => 'Votre demande de certification a été annulée avec succès (frais de traitement non remboursés).',
        ]);
    }
}
