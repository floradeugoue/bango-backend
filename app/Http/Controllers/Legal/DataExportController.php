<?php

namespace App\Http\Controllers\Legal;

use App\Enums\Legal\DataExportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Legal\DataExportRequestResource;
use App\Jobs\Legal\GenerateDataExportJob;
use App\Models\Legal\DataExportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DataExportController extends Controller
{
    /**
     * Déclenche une demande d'export asynchrone des données personnelles RGPD (LG-10).
     *
     * POST /api/user/data-export
     */
    public function requestExport(Request $request): JsonResponse
    {
        $user = $request->user();

        // Éviter les demandes répétées ou simultanées
        $pending = DataExportRequest::where('user_id', $user->id)
            ->whereIn('status', [DataExportStatus::Pending->value, DataExportStatus::Processing->value])
            ->first();

        if ($pending) {
            return response()->json([
                'message' => 'Une demande d\'export est déjà en cours de génération.',
                'export' => new DataExportRequestResource($pending),
            ], 422);
        }

        $validated = $request->validate([
            'sections' => ['nullable', 'array'],
            'sections.*' => ['string'],
        ]);

        $exportRequest = DataExportRequest::create([
            'user_id' => $user->id,
            'status' => DataExportStatus::Pending,
            'sections' => $validated['sections'] ?? null,
            'disk' => 'local',
        ]);

        GenerateDataExportJob::dispatch($exportRequest);

        return response()->json([
            'message' => 'Votre demande d\'export a été prise en compte. L\'archive sera prête sous peu.',
            'export' => new DataExportRequestResource($exportRequest),
        ], 202);
    }

    /**
     * Vérifie le statut de la dernière demande d'export de l'utilisateur.
     *
     * GET /api/user/data-export/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $latest = DataExportRequest::where('user_id', $user->id)
            ->latest()
            ->first();

        if (! $latest) {
            return response()->json([
                'status' => null,
                'message' => 'Aucune demande d\'export enregistrée.',
            ], 200);
        }

        return response()->json([
            'export' => new DataExportRequestResource($latest),
        ], 200);
    }

    /**
     * Télécharge l'archive ZIP générée (privée et temporaire).
     *
     * GET /api/user/data-export/download/{id}
     */
    public function download(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        $user = $request->user();

        $exportRequest = DataExportRequest::find($id);

        if (! $exportRequest) {
            return response()->json([
                'message' => 'Demande d\'export introuvable.',
            ], 404);
        }

        // Protection IDOR : accès strictement réservé au propriétaire
        if ((int) $exportRequest->user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Accès non autorisé à cette archive.',
            ], 403);
        }

        if (! $exportRequest->isDownloadable()) {
            return response()->json([
                'message' => 'L\'archive n\'est pas prête ou a expiré.',
            ], 410);
        }

        $fullPath = storage_path('app/'.$exportRequest->file_path);

        if (! file_exists($fullPath)) {
            return response()->json([
                'message' => 'Le fichier d\'archive est introuvable sur le serveur.',
            ], 404);
        }

        return response()->download($fullPath, 'bango_mes_donnees_'.$user->id.'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Annule une demande d'export en attente ou en cours.
     *
     * POST /api/user/data-export/{id}/cancel
     * POST /api/settings/export/{id}/cancel
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $exportRequest = DataExportRequest::where('id', $id)->firstOrFail();

        if ((int) $exportRequest->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if ($exportRequest->status === DataExportStatus::Cancelled) {
            return response()->json([
                'message' => 'Cette demande d\'export est déjà annulée.',
            ], 422);
        }

        if ($exportRequest->file_path && Storage::disk($exportRequest->disk)->exists($exportRequest->file_path)) {
            Storage::disk($exportRequest->disk)->delete($exportRequest->file_path);
        }

        $exportRequest->update([
            'status' => DataExportStatus::Cancelled,
            'file_path' => null,
        ]);

        return response()->json([
            'message' => 'Demande d\'export annulée avec succès.',
            'export' => new DataExportRequestResource($exportRequest),
        ], 200);
    }
}
