<?php

namespace App\Http\Controllers\Admin\Kyc;

use App\Enums\Kyc\KycDocKind;
use App\Http\Controllers\Controller;
use App\Models\Admin\AdminAuditLog;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycDocumentAccessLog;
use App\Models\Kyc\KycSubmission;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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

        $isSelfie = $document->kind === KycDocKind::Selfie;
        $defaultAction = $isSelfie ? 'view_selfie' : 'open';
        $action = (string) $request->input('action', $defaultAction);

        // 1. Enregistrement immuable dans le journal d'audit des consultations sensibles
        KycDocumentAccessLog::create([
            'operator_id' => $request->user()->id,
            'kyc_submission_id' => $submission->id,
            'kyc_document_id' => $document->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        // Point 21 & Traçabilité selfie : Journalisation d'audit chaînée
        $event = $isSelfie ? 'kyc.selfie_viewed' : 'kyc.document_opened';
        $targetLabel = $isSelfie
            ? "Consultation du selfie de face du dossier {$submission->reference} (utilisateur #{$submission->user_id})"
            : "Ouverture pièce KYC {$document->kind?->value} ({$document->original_name}) du dossier {$submission->reference}";

        AdminAuditLog::logChainedEvent(
            event: $event,
            operator: $request->user(),
            targetType: 'KycDocument',
            targetId: (string) $document->id,
            targetLabel: $targetLabel,
            context: [
                'submission_id' => $submission->id,
                'submission_reference' => $submission->reference,
                'document_id' => $document->id,
                'document_kind' => $document->kind?->value,
                'is_selfie' => $isSelfie,
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

        // 3. Téléchargement ou affichage sécurisé en flux binaire contrôlé déchiffré
        $privateDisk = config('media.private_disk', 'local');
        $diskInstance = Storage::disk($privateDisk);
        if (! $diskInstance->exists($document->file_path)) {
            if (Storage::disk('local')->exists($document->file_path)) {
                $diskInstance = Storage::disk('local');
            } else {
                abort(404, 'Fichier introuvable sur le stockage sécurisé.');
            }
        }

        $rawContent = $diskInstance->get($document->file_path);
        try {
            $decryptedContent = Crypt::decrypt($rawContent);
        } catch (DecryptException) {
            $decryptedContent = $rawContent;
        }

        return response()->streamDownload(
            function () use ($decryptedContent) {
                echo $decryptedContent;
            },
            $document->original_name,
            [
                'Content-Type' => $document->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * Enregistre explicitement la consultation d'un selfie dans l'historique d'audit du back-office.
     */
    public function logSelfieView(Request $request, string $id): Response
    {
        $submission = Str::isUuid($id)
            ? KycSubmission::where(fn ($q) => $q->where('id', $id)->orWhere('reference', $id))->firstOrFail()
            : KycSubmission::where('reference', $id)->firstOrFail();

        /** @var KycDocument|null $selfie */
        $selfie = $submission->documents()->where('kind', KycDocKind::Selfie)->first();

        if (! $selfie) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun selfie trouvé pour ce dossier.',
            ], 404);
        }

        KycDocumentAccessLog::create([
            'operator_id' => $request->user()->id,
            'kyc_submission_id' => $submission->id,
            'kyc_document_id' => $selfie->id,
            'action' => 'view_selfie',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        AdminAuditLog::logChainedEvent(
            event: 'kyc.selfie_viewed',
            operator: $request->user(),
            targetType: 'KycDocument',
            targetId: (string) $selfie->id,
            targetLabel: "Consultation du selfie de face du dossier {$submission->reference} (utilisateur #{$submission->user_id})",
            context: [
                'submission_id' => $submission->id,
                'submission_reference' => $submission->reference,
                'document_id' => $selfie->id,
                'document_kind' => 'selfie',
                'is_selfie' => true,
            ],
            ip: $request->ip(),
            userAgent: $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Consultation du selfie enregistrée dans l\'historique.',
            'data' => [
                'submission_id' => $submission->id,
                'document_id' => $selfie->id,
                'action' => 'view_selfie',
                'accessed_at' => now()->toISOString(),
            ],
        ]);
    }
}
