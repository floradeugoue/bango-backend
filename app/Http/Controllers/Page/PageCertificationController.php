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
     */
    public function initiatePayment(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('submitCertification', $page);

        $request->validate([
            'provider' => ['required', 'string', 'in:orange,mtn,moov,wave,cash'],
            'phone_number' => ['required', 'string', 'max:20'],
            'price_id' => ['nullable', 'integer', 'exists:subscription_prices,id'],
            'gateway' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $transaction = $this->certificationService->initiatePayment(
            page: $page,
            user: $request->user(),
            paymentData: $request->all()
        );

        return (new PaymentTransactionResource($transaction))
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
}
