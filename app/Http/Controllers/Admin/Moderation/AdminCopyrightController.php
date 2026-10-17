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
            'content_type' => $request->query('content_type'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
        ];

        $perPage = (int) $request->query('per_page', 20);
        $claims = $this->copyrightService->getClaimsQueue($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $claims->items(),
            'pagination' => [
                'current_page' => $claims->currentPage(),
                'per_page' => $claims->perPage(),
                'total' => $claims->total(),
                'last_page' => $claims->lastPage(),
            ],
        ]);
    }

    /**
     * Décision sur une revendication de droits d'auteur (POST /moderation/rights/{ref}/decision).
     */
    public function decision(CopyrightDecisionRequest $request, string $ref): JsonResponse
    {
        $operator = $request->user();

        try {
            $claim = $this->copyrightService->resolveClaim(
                claimRef: $ref,
                decision: $request->input('decision'),
                decisionNotes: $request->input('decision_notes'),
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
                'resolved_by' => $claim->resolved_by,
                'resolved_at' => $claim->resolved_at?->toIso8601String(),
            ],
        ]);
    }
}
