<?php

namespace App\Http\Controllers\Support;

use App\Enums\Support\AppealStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreModerationAppealRequest;
use App\Http\Resources\Support\ModerationAppealResource;
use App\Models\Page\Page;
use App\Models\Support\ModerationAppeal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ModerationAppealController extends Controller
{
    /**
     * Dépose un recours formel contre une sanction ou suspension.
     *
     * POST /api/moderation/appeals
     */
    public function store(StoreModerationAppealRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // 1. Contrôle de légitimité de la contestation
        if ($validated['sanction_type'] === 'account_suspension') {
            if ($user->status !== 'suspended' && ! $user->restricted_until) {
                return response()->json([
                    'message' => 'Votre compte ne fait l\'objet d\'aucune suspension active.',
                ], 422);
            }
        } elseif ($validated['sanction_type'] === 'page_suspension') {
            if (empty($validated['sanction_reference'])) {
                return response()->json([
                    'message' => 'L\'identifiant de la Page Professionnelle est requis pour ce recours.',
                ], 422);
            }

            $page = Page::find($validated['sanction_reference']);
            if (! $page || ! $page->canUserPublish($user)) {
                return response()->json([
                    'message' => 'Vous n\'êtes pas autorisé à contester la sanction pour cette Page.',
                ], 403);
            }

            if ($page->status !== 'suspended') {
                return response()->json([
                    'message' => 'Cette Page Professionnelle n\'est pas suspendue.',
                ], 422);
            }
        }

        // 2. Prévention des recours en doublon
        $existing = ModerationAppeal::where('user_id', $user->id)
            ->where('sanction_type', $validated['sanction_type'])
            ->where('status', AppealStatus::Pending->value)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Un recours pour cette sanction est déjà en cours d\'instruction.',
                'appeal' => new ModerationAppealResource($existing),
            ], 422);
        }

        // 3. Enregistrement du recours (sans levée automatique de sanction)
        $appeal = ModerationAppeal::create([
            'user_id' => $user->id,
            'sanction_type' => $validated['sanction_type'],
            'sanction_reference' => $validated['sanction_reference'] ?? null,
            'reason' => $validated['reason'],
            'explanation' => $validated['explanation'],
            'contact_email' => $validated['contact_email'] ?? $user->email,
            'status' => AppealStatus::Pending,
        ]);

        return (new ModerationAppealResource($appeal))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Liste des recours déposés par l'utilisateur connecté.
     *
     * GET /api/moderation/appeals
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $appeals = ModerationAppeal::where('user_id', $request->user()->id)
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return ModerationAppealResource::collection($appeals);
    }
}
