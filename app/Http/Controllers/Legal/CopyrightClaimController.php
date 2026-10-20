<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Legal\StoreCopyrightClaimRequest;
use App\Models\Support\CopyrightClaim;
use Illuminate\Http\JsonResponse;

class CopyrightClaimController extends Controller
{
    /**
     * Enregistrer un signalement formel pour atteinte aux droits d'auteur (LG-17).
     *
     * POST /api/legal/copyright-claims
     */
    public function store(StoreCopyrightClaimRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();
        if ($user) {
            $validated['claimant_id'] = $user->id;
        }

        $validated['status'] = 'pending';

        $claim = CopyrightClaim::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Votre signalement d\'atteinte aux droits d\'auteur a été enregistré avec succès et sera instruit par notre équipe juridique.',
            'data' => [
                'id' => $claim->id,
                'reference' => $claim->reference,
                'work_title' => $claim->work_title,
                'content_type' => $claim->content_type,
                'content_id' => $claim->content_id,
                'status' => $claim->status,
                'created_at' => $claim->created_at->toIso8601String(),
            ],
        ], 201);
    }
}
