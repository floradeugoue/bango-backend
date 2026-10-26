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
            ->with(['decisions', 'reportable'])
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return ReportResource::collection($reports);
    }

    /**
     * Demande un réexamen d'un signalement traité ou classé (SUP-14).
     *
     * POST /api/reports/{id}/reexamine
     */
    public function reexamine(Request $request, string|int $id): JsonResponse
    {
        $user = $request->user();

        /** @var Report $report */
        $report = Report::where('id', is_numeric($id) ? (int) $id : 0)
            ->orWhere('reference', (string) $id)
            ->firstOrFail();

        // 1. Contrôle d'accès : seul le déclarant d'origine peut demander un réexamen
        if ((int) $report->reporter_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Non autorisé. Vous n\'êtes pas l\'auteur de ce signalement.',
            ], 403);
        }

        // 2. Validation du message
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $meta = $report->metadata ?? [];

        // 3. Règle métier : une seule demande de réexamen par signalement
        if (! empty($meta['reexamination_requested'])) {
            return response()->json([
                'message' => 'Une demande de réexamen a déjà été enregistrée pour ce signalement.',
            ], 422);
        }

        // 4. Mise à jour du dossier : remise en file avec mention « réexamen demandé »
        $meta['reexamination_requested'] = true;
        $meta['reexamined_at'] = now()->toIso8601String();
        $meta['reexamination_message'] = $validated['message'];
        $meta['reexamination_history'] = array_merge($meta['reexamination_history'] ?? [], [
            [
                'message' => $validated['message'],
                'requested_at' => now()->toIso8601String(),
                'previous_status' => $report->status->value,
                'previous_action' => $report->action_taken,
            ],
        ]);

        $report->status = ReportStatus::Pending;
        $report->priority = 'high'; // Prioriser le réexamen dans la file de modération
        $report->escalation_reason = 'réexamen demandé : '.$validated['message'];
        $report->assigned_to = null; // Remis dans le pool non assigné pour réattribution
        $report->metadata = $meta;
        $report->save();

        return (new ReportResource($report))
            ->response()
            ->setStatusCode(201);
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
