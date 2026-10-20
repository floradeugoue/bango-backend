<?php

namespace App\Http\Controllers\Admin\Kyc;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminAuditLog;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycDocumentAccessLog;
use App\Models\Kyc\KycSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AdminKycDocumentController extends Controller
{
    /**
     * A.7. Consultation sécurisée d'une pièce d'identité avec traçabilité et journalisation d'audit.
     */
    public function open(Request $request, string $id, string $docId): Response
    {
        $submission = Str::isUuid($id)
            ? KycSubmission::where(fn ($q) => $q->where('id', $id)->orWhere('reference', $id))->firstOrFail()
            : KycSubmission::where('reference', $id)->firstOrFail();

        if (! Str::isUuid($docId)) {
            abort(404, 'Pièce KYC introuvable.');
        }

        /** @var KycDocument $document */
        $document = $submission->documents()->where('id', $docId)->firstOrFail();

        // 1. Enregistrement immuable dans le journal d'audit des consultations sensibles
        KycDocumentAccessLog::create([
            'operator_id' => $request->user()->id,
            'kyc_submission_id' => $submission->id,
            'kyc_document_id' => $document->id,
            'action' => (string) $request->input('action', 'open'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        // Point 21 : Journalisation d'audit chaînée de l'ouverture de pièce KYC
        AdminAuditLog::logChainedEvent(
            event: 'kyc.document_opened',
            operator: $request->user(),
            targetType: 'KycDocument',
            targetId: (string) $document->id,
            targetLabel: "Ouverture pièce KYC {$document->kind?->value} ({$document->original_name}) du dossier {$submission->reference}",
            context: [
                'submission_id' => $submission->id,
                'submission_reference' => $submission->reference,
                'document_id' => $document->id,
                'original_name' => $document->original_name,
                'mime_type' => $document->mime_type,
            ],
            ip: $request->ip(),
            userAgent: $request->userAgent()
        );

        // 2. Si le client demande du JSON (ex: pour obtenir un aperçu ou token sécurisé temporaire)
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Consultation de document journalisée avec succès.',
                'data' => [
                    'id' => $document->id,
                    'kind' => $document->kind->value,
                    'original_name' => $document->original_name,
                    'mime_type' => $document->mime_type,
                    'file_size' => $document->file_size,
                    'accessed_at' => now()->toISOString(),
                    'operator_id' => $request->user()->id,
                ],
            ]);
        }

        // 3. Téléchargement ou affichage sécurisé en flux binaire contrôlé
        if (! Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'Fichier introuvable sur le stockage sécurisé.');
        }

        return Storage::disk('local')->response(
            $document->file_path,
            $document->original_name,
            [
                'Content-Type' => $document->mime_type,
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
