<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\UploadEvidenceRequest;
use App\Models\Support\ModerationEvidence;
use App\Models\Support\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminModerationEvidenceController extends Controller
{
    /**
     * Ouvre et journalise l'accès à une preuve d'un dossier (POST /moderation/cases/{ref}/evidence/{id}/open).
     */
    public function open(Request $request, string $ref, int|string $id): JsonResponse
    {
        $report = Report::where('reference', $ref)
            ->orWhere('id', $ref)
            ->first();

        if (! $report) {
            throw new NotFoundHttpException("Dossier de modération [{$ref}] introuvable.");
        }

        $evidence = ModerationEvidence::find($id);

        if (! $evidence) {
            throw new NotFoundHttpException("Preuve [{$id}] introuvable.");
        }

        // Vérification stricte : la preuve doit appartenir au dossier demandé
        if ((int) $evidence->report_id !== (int) $report->id) {
            return response()->json([
                'success' => false,
                'message' => "Accès refusé : la preuve n'appartient pas à ce dossier de modération.",
            ], 403);
        }

        $operator = $request->user();

        // Journalisation de sécurité
        Log::info('Accès preuve de modération', [
            'operator_id' => $operator?->id,
            'operator_email' => $operator?->email,
            'report_reference' => $report->reference,
            'report_id' => $report->id,
            'evidence_id' => $evidence->id,
            'file_name' => $evidence->file_name,
            'ip' => $request->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);

        $disk = Storage::disk('local');
        $exists = $disk->exists($evidence->file_path);

        $url = null;
        if (Storage::disk('public')->exists($evidence->file_path)) {
            $url = Storage::disk('public')->url($evidence->file_path);
        } else {
            // URL temporaire ou route sécurisée
            $url = url("/api/admin/moderation/evidence/{$evidence->id}/stream");
        }

        return response()->json([
            'success' => true,
            'message' => 'Accès à la preuve autorisé et journalisé.',
            'data' => [
                'evidence' => [
                    'id' => $evidence->id,
                    'file_name' => $evidence->file_name,
                    'mime_type' => $evidence->mime_type,
                    'file_size' => $evidence->file_size,
                    'file_url' => $url,
                    'exists' => $exists || ! empty($url),
                    'created_at' => $evidence->created_at?->toIso8601String(),
                ],
                'case_reference' => $report->reference,
                'opened_by' => $operator?->email,
                'opened_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Téléversement sécurisé d'une preuve de modération (POST /uploads ou POST /moderation/uploads).
     */
    public function upload(UploadEvidenceRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $operator = $request->user();

        // Nom de fichier assaini et aléatoire pour éviter toute injection ou écrasement
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $safeFileName = 'evidence_'.now()->format('YmdHis').'_'.Str::random(12).'.'.$extension;
        $folder = 'moderation/evidences/'.now()->format('Y/m');

        // Stockage sur disque local
        $path = $file->storeAs($folder, $safeFileName, 'local');

        // Association optionnelle à un dossier de modération
        $reportId = null;
        if ($request->filled('report_ref')) {
            $report = Report::where('reference', $request->input('report_ref'))
                ->orWhere('id', $request->input('report_ref'))
                ->first();
            if ($report) {
                $reportId = $report->id;
            }
        }

        $evidence = ModerationEvidence::create([
            'report_id' => $reportId,
            'file_path' => $path,
            'file_name' => $safeFileName,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'uploader_id' => $operator?->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Fichier de preuve téléversé avec succès.',
            'data' => [
                'id' => $evidence->id,
                'file_name' => $evidence->file_name,
                'file_size' => $evidence->file_size,
                'mime_type' => $evidence->mime_type,
                'report_id' => $evidence->report_id,
                'report_ref' => $request->input('report_ref'),
                'uploaded_at' => $evidence->created_at->toIso8601String(),
            ],
        ], 201);
    }
}
