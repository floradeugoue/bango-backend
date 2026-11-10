<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\CopyrightDecisionRequest;
use App\Services\Admin\CopyrightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminCopyrightController extends Controller
{
    public function __construct(
        protected CopyrightService $copyrightService
    ) {}

    /**
     * File des revendications de propriété intellectuelle (GET /moderation/rights).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->query('status'),
            'claim_kind' => $request->query('claim_kind'),
            'country' => $request->query('country'),
            'content_type' => $request->query('content_type'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'q' => $request->query('q'),
        ];

        $perPage = (int) $request->query('per_page', $request->query('perPage', 20));
        $claims = $this->copyrightService->getClaimsQueue($filters, $perPage);
        $meta = $this->copyrightService->getClaimsMeta(90);

        return response()->json([
            'items' => $claims->items(),
            'page' => $claims->currentPage(),
            'perPage' => $claims->perPage(),
            'total' => $claims->total(),
            'pageCount' => $claims->lastPage(),
            'success' => true,
            'data' => $claims->items(),
            'pagination' => [
                'current_page' => $claims->currentPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
                'last_page' => $claims->lastPage(),
            ],
            'counters' => $meta['counts'],
            'meta' => $meta,
        ]);
    }

    /**
     * Décision sur une revendication de droits d'auteur (POST /moderation/rights/{ref}/decision).
     */
    public function decision(CopyrightDecisionRequest $request, string $ref): JsonResponse
    {
        $operator = $request->attributes->get('admin_operator') ?? $request->user();
        $decisionNotes = (string) ($request->input('decision_notes') ?? $request->input('message') ?? '');

        try {
            $claim = $this->copyrightService->resolveClaim(
                claimRef: $ref,
                decision: $request->input('decision'),
                decisionNotes: $decisionNotes,
                operator: $operator
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'claim' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Décision sur la revendication {$claim->reference} enregistrée avec succès ({$claim->decision}).",
            'data' => [
                'reference' => $claim->reference,
                'status' => $claim->status,
                'decision' => $claim->decision,
                'decision_notes' => $claim->decision_notes,
                'message' => $claim->decision === 'request_info' ? $claim->decision_notes : null,
                'resolved_by' => $claim->resolved_by,
                'resolved_at' => $claim->resolved_at?->toIso8601String(),
            ],
        ]);
    }
}
