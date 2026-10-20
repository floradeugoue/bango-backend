<?php

namespace App\Http\Controllers\Support;

use App\Enums\Support\AppealStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreModerationAppealRequest;
use App\Http\Resources\Support\ModerationAppealResource;
use App\Models\Page\Page;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationDecision;
use App\Models\Support\Report;
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

        $decision = null;
        $sanctionOperatorId = null;

        // 1. Si une décision de modération est spécifiée, lire la cible et le type depuis la décision vérifiée
        if (! empty($validated['moderation_decision_id'])) {
            /** @var ModerationDecision|null $decision */
            $decision = ModerationDecision::with('report')->find($validated['moderation_decision_id']);
            if (! $decision) {
                return response()->json([
                    'message' => 'Décision de modération introuvable.',
                ], 404);
            }

            $report = $decision->report;
            $isConcerned = false;
            $sanctionType = null;
            $sanctionReference = null;

            if ($report) {
                $reportableType = strtolower($report->reportable_type);
                $reportableId = $report->reportable_id;

                if (in_array($reportableType, ['user', 'users', 'app\models\user', 'account', 'profile'], true)) {
                    if ((int) $reportableId === (int) $user->id) {
                        $isConcerned = true;
                        $sanctionType = 'account_suspension';
                        $sanctionReference = (string) $user->id;
                    }
                } elseif (in_array($reportableType, ['page', 'pages', 'app\models\page\page'], true)) {
                    $page = Page::find($reportableId);
                    if ($page && ($page->user_id === $user->id || (method_exists($page, 'canUserPublish') && $page->canUserPublish($user)))) {
                        $isConcerned = true;
                        $sanctionType = 'page_suspension';
                        $sanctionReference = (string) $page->id;
                    }
                } else {
                    $targetClass = Report::resolveReportableClass($report->reportable_type);
                    if ($targetClass && class_exists($targetClass)) {
                        $content = $targetClass::find($reportableId) ?? (method_exists($targetClass, 'withTrashed') ? $targetClass::withTrashed()->find($reportableId) : null);
                        $authorId = $content?->user_id ?? $content?->owner_id ?? null;
                        if ($authorId && (int) $authorId === (int) $user->id) {
                            $isConcerned = true;
                            $sanctionType = 'content_removal';
                            $sanctionReference = (string) ($report->reference ?? $report->id);
                        }
                    }
                }
            }

            if (! $isConcerned && ! empty($decision->metadata['target_id'])) {
                if (($decision->metadata['target_type'] ?? '') === 'User' && (int) $decision->metadata['target_id'] === (int) $user->id) {
                    $isConcerned = true;
                    $sanctionType = 'account_suspension';
                    $sanctionReference = (string) $user->id;
                }
            }

            if ($decision->action === 'suspend_author') {
                $sanctionType = 'account_suspension';
                $sanctionReference = (string) $user->id;
                $isConcerned = true;
            } elseif ($decision->action === 'warn_author') {
                $sanctionType = 'warning';
                $sanctionReference = (string) $user->id;
                $isConcerned = true;
            }

            if (! $isConcerned) {
                return response()->json([
                    'message' => 'Cette décision de modération ne vous concerne pas.',
                ], 403);
            }

            $validated['sanction_type'] = $sanctionType ?? $validated['sanction_type'] ?? 'account_suspension';
            $validated['sanction_reference'] = $sanctionReference ?? $validated['sanction_reference'] ?? null;
            $sanctionOperatorId = $decision->operator_id;
        }

        // 2. Contrôle de légitimité de la contestation
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

        // 3. Prévention des recours en doublon
        $duplicateQuery = ModerationAppeal::where('user_id', $user->id)
            ->where('status', AppealStatus::Pending->value);

        if ($decision) {
            $duplicateQuery->where('moderation_decision_id', $decision->id);
        } else {
            $duplicateQuery->where('sanction_type', $validated['sanction_type']);
        }

        $existing = $duplicateQuery->first();

        if ($existing) {
            return response()->json([
                'message' => 'Un recours pour cette sanction est déjà en cours d\'instruction.',
                'appeal' => new ModerationAppealResource($existing),
            ], 422);
        }

        // 4. Enregistrement du recours rattaché à la vraie sanction
        $appeal = ModerationAppeal::create([
            'user_id' => $user->id,
            'moderation_decision_id' => $decision?->id,
            'sanction_operator_id' => $sanctionOperatorId,
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
