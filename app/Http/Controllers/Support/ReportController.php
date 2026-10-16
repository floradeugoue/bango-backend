<?php

namespace App\Http\Controllers\Support;

use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreReportRequest;
use App\Http\Resources\Support\ReportResource;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\Support\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController extends Controller
{
    /**
     * Crée un nouveau signalement universel.
     *
     * POST /api/reports
     */
    public function store(StoreReportRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $targetClass = Report::resolveReportableClass($validated['target_type']);
        if (! $targetClass) {
            return response()->json([
                'message' => 'Type de contenu non pris en charge pour le signalement.',
            ], 422);
        }

        $target = $targetClass::find($validated['target_id']);
        if (! $target) {
            return response()->json([
                'message' => 'Contenu ou ressource cible introuvable.',
            ], 404);
        }

        // Vérification de l'auto-signalement abusif
        if ($this->isSelfReported($user, $target)) {
            return response()->json([
                'message' => 'Vous ne pouvez pas signaler votre propre contenu ou votre propre profil.',
            ], 422);
        }

        // Déduplication : si un signalement pour ce contenu est déjà en cours par cet utilisateur
        $existing = Report::where('reporter_id', $user->id)
            ->where('reportable_type', $target->getMorphClass())
            ->where('reportable_id', (string) $target->getKey())
            ->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Investigating->value])
            ->first();

        if ($existing) {
            return (new ReportResource($existing))
                ->additional(['message' => 'Un signalement pour ce contenu est déjà en cours de traitement.'])
                ->response()
                ->setStatusCode(200);
        }

        $report = Report::create([
            'reporter_id' => $user->id,
            'reportable_type' => $target->getMorphClass(),
            'reportable_id' => (string) $target->getKey(),
            'reason' => ReportReason::from($validated['reason']),
            'details' => $validated['details'] ?? null,
            'status' => ReportStatus::Pending,
            'metadata' => [
                'target_label' => method_exists($target, 'getTitle') ? $target->getTitle() : ($target->title ?? $target->name ?? null),
            ],
        ]);

        return (new ReportResource($report))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Liste des signalements émis par l'utilisateur connecté.
     *
     * GET /api/reports/mine
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $reports = Report::where('reporter_id', $request->user()->id)
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return ReportResource::collection($reports);
    }

    /**
     * Détecte si le contenu ciblé appartient au déclarant.
     */
    protected function isSelfReported(User $user, object $target): bool
    {
        if ($target instanceof User) {
            return (int) $target->id === (int) $user->id;
        }

        if ($target instanceof Page) {
            return (int) $target->owner_id === (int) $user->id;
        }

        if ($target instanceof Message) {
            return (int) $target->sender_id === (int) $user->id;
        }

        if (isset($target->user_id)) {
            return (int) $target->user_id === (int) $user->id;
        }

        if (isset($target->owner_id)) {
            return (int) $target->owner_id === (int) $user->id;
        }

        return false;
    }
}
