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

class PageCertificationAdminController extends Controller
{
    public function __construct(
        protected PageCertificationService $certificationService,
        protected PageService $pageService
    ) {}

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
