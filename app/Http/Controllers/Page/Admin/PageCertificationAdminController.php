<?php

namespace App\Http\Controllers\Page\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Page\AdminDecideCertificationRequest;
use App\Http\Requests\Page\AdminSuspendPageRequest;
use App\Http\Resources\Page\PageCertificationResource;
use App\Http\Resources\Page\PageResource;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Services\Page\PageCertificationService;
use App\Services\Page\PageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PageCertificationAdminController extends Controller
{
    public function __construct(
        protected PageCertificationService $certificationService,
        protected PageService $pageService
    ) {}

    /**
     * GET /api/admin/pages
     * Liste paginée des pages professionnelles pour l'administration (recherche, filtres, statuts).
     */
    public function indexPages(Request $request): JsonResponse
    {
        $filters = $request->only([
            'q',
            'search',
            'city',
            'sector',
            'status',
            'certification',
            'certification_status',
            'sort',
            'order',
        ]);

        $perPage = (int) $request->query('per_page', 20);
        $result = $this->pageService->listAdminPages($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $result['paginator']->items(),
            'items' => $result['paginator']->items(),
            'counts' => $result['counts'],
            'meta' => [
                'current_page' => $result['paginator']->currentPage(),
                'per_page' => $result['paginator']->perPage(),
                'total' => $result['paginator']->total(),
                'last_page' => $result['paginator']->lastPage(),
                'counts' => $result['counts'],
            ],
        ]);
    }

    /**
     * GET /api/admin/pages/{page}
     * Fiche complète détaillée d'une page professionnelle pour le back-office.
     */
    public function showPage(int|string $page): JsonResponse
    {
        $detail = $this->pageService->getAdminPageDetail($page);

        return response()->json([
            'success' => true,
            'data' => $detail,
            'page' => $detail['page'],
            'owner' => $detail['owner'],
            'members' => $detail['members'],
            'posts' => $detail['posts'],
            'reports' => $detail['reports'],
            'certification' => $detail['certification'],
            'certifications_history' => $detail['certifications_history'],
            'subscription' => $detail['subscription'],
            'subscriptions_history' => $detail['subscriptions_history'],
            'stats' => $detail['stats'],
        ]);
    }

    /**
     * Liste tous les dossiers de certification pour l'administration.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PageCertification::with(['page', 'submitter', 'reviewer'])
            ->orderByDesc('created_at');

        if ($request->has('status') && $request->string('status')->isNotEmpty()) {
            $query->where('status', $request->string('status'));
        }

        return PageCertificationResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * Traite un dossier de certification (Approbation, Refus ou Pièces complémentaires).
     */
    public function decide(AdminDecideCertificationRequest $request, PageCertification $certification): PageCertificationResource
    {
        $page = $certification->page()->withTrashed()->first();
        if (! $page || $page->trashed()) {
            throw ValidationException::withMessages([
                'certification' => ['Impossible de statuer sur la certification d\'une page supprimée.'],
            ]);
        }

        $decision = $request->validated('decision');
        $reason = $request->validated('reason');

        $result = match ($decision) {
            'approve' => $this->certificationService->adminApprove($certification, $request->user()),
            'reject' => $this->certificationService->adminReject($certification, $request->user(), $reason ?? 'Dossier rejeté'),
            'supplement_requested' => $this->certificationService->adminRequestSupplement($certification, $request->user(), $reason ?? 'Pièces requises'),
        };

        return new PageCertificationResource($result);
    }

    /**
     * Télécharge une pièce justificative d'un dossier de certification sous session administrative.
     */
    public function downloadDocument(Request $request, PageCertification $certification, string $key): StreamedResponse
    {
        return $this->certificationService->downloadDocument($certification, $key, $request->user());
    }

    /**
     * Suspend la certification d'une Page (EDGE-03).
     */
    public function suspendCertification(Request $request, Page $page): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->certificationService->adminSuspendCertification(
            page: $page,
            admin: $request->user(),
            reason: $request->input('reason', 'Certification suspendue par l\'administration')
        );

        return response()->json([
            'message' => 'La certification de la page a été suspendue avec succès.',
            'page' => new PageResource($page->fresh()),
        ]);
    }

    /**
     * Rétablit la certification d'une Page.
     */
    public function restoreCertification(Request $request, Page $page): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'motif' => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = $request->input('reason') ?? $request->input('motif') ?? 'Certification rétablie par l\'administration';

        $this->certificationService->adminRestoreCertification(
            page: $page,
            admin: $request->user(),
            reason: $reason
        );

        return response()->json([
            'message' => 'La certification de la page a été rétablie avec succès.',
            'page' => new PageResource($page->fresh()),
        ]);
    }

    /**
     * Suspend une Page opérationnellement (EDGE-04).
     */
    public function suspendPage(AdminSuspendPageRequest $request, Page $page): JsonResponse
    {
        $suspended = $this->pageService->suspendPage(
            page: $page,
            admin: $request->user(),
            reason: $request->validated('reason')
        );

        return response()->json([
            'message' => 'La page a été suspendue avec succès.',
            'page' => new PageResource($suspended),
        ]);
    }

    /**
     * Lève la suspension opérationnelle d'une Page.
     */
    public function unsuspendPage(Request $request, Page $page): JsonResponse
    {
        $unsuspended = $this->pageService->unsuspendPage(
            page: $page,
            admin: $request->user()
        );

        return response()->json([
            'message' => 'La suspension de la page a été levée avec succès.',
            'page' => new PageResource($unsuspended),
        ]);
    }
}
