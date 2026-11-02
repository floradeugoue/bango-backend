<?php

namespace App\Services\Kyc;

use App\Enums\Kyc\KycDocKind;
use App\Enums\Kyc\KycStatus;
use App\Models\Admin\AdminAuditLog;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycSubmission;
use App\Models\User;
use App\Notifications\Kyc\KycSubmittedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycService
{
    public const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10MB

    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(
        protected FaceVerificationService $faceVerificationService
    ) {}

    /**
     * Soumet un dossier KYC avec téléversement sécurisé en stockage privé.
     *
     * @param  array{
     *     full_name?: ?string,
     *     id_type?: ?string,
     *     id_number?: ?string,
     *     expires_on?: ?string,
     *     property_right?: ?string,
     *     deed_type?: ?string
     * }  $data
     * @param  array<string, UploadedFile|array<UploadedFile>>  $files
     */
    public function submitKyc(User $user, array $data, array $files): KycSubmission
    {
        if (empty($files)) {
            throw ValidationException::withMessages([
                'files' => ['Veuillez fournir au moins un document justificatif (pièce d\'identité ou justificatif de propriété).'],
            ]);
        }

        return DB::transaction(function () use ($user, $data, $files) {
            $requestedBadge = $data['requested_badge'] ?? 'landlord';

            $submission = KycSubmission::create([
                'user_id' => $user->id,
                'status' => KycStatus::Pending,
                'profile_category' => $requestedBadge === 'double' ? 'double' : ($requestedBadge === 'agent' ? 'agent' : 'landlord'),
                'full_name' => $data['full_name'] ?? $user->name,
                'id_type' => $data['id_type'] ?? null,
                'id_number' => $data['id_number'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'property_right' => $data['property_right'] ?? null,
                'deed_type' => $data['deed_type'] ?? null,
                'metadata' => [
                    'requested_badge' => $requestedBadge,
                ],
            ]);

            $idFrontFile = null;
            $selfieFile = null;
            $livenessFiles = [];

            foreach ($files as $key => $fileOrArray) {
                $fileList = is_array($fileOrArray) ? $fileOrArray : [$fileOrArray];

                foreach ($fileList as $file) {
                    if (! ($file instanceof UploadedFile)) {
                        continue;
                    }

                    $this->validateFile($file);

                    $kind = KycDocKind::tryFrom($key) ?? match ($key) {
                        'identity_front', 'id_card_front', 'front' => KycDocKind::Front,
                        'identity_back', 'id_card_back', 'back' => KycDocKind::Back,
                        'selfie' => KycDocKind::Selfie,
                        'liveness_frames', 'liveness_frame' => KycDocKind::LivenessFrame,
                        'deed', 'property_deed', 'property_deeds' => KycDocKind::Deed,
                        default => KycDocKind::Other,
                    };

                    if ($kind === KycDocKind::Front && $idFrontFile === null) {
                        $idFrontFile = $file;
                    } elseif ($kind === KycDocKind::Selfie && $selfieFile === null) {
                        $selfieFile = $file;
                    } elseif ($kind === KycDocKind::LivenessFrame) {
                        $livenessFiles[] = $file;
                    }

                    $extension = $file->getClientOriginalExtension() ?: 'bin';
                    $fileName = (string) Str::uuid().'.'.$extension;
                    $privatePath = "kyc/{$user->id}/{$fileName}";

                    // Chiffrement au repos du fichier KYC (AES-256 via Crypt)
                    $fileContent = file_get_contents($file->getRealPath());
                    $encryptedContent = Crypt::encrypt($fileContent);
                    Storage::disk('local')->put($privatePath, $encryptedContent);

                    KycDocument::create([
                        'kyc_submission_id' => $submission->id,
                        'user_id' => $user->id,
                        'kind' => $kind,
                        'deed_type' => $kind === KycDocKind::Deed ? ($data['deed_type'] ?? null) : null,
                        'file_path' => $privatePath,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'file_size' => $file->getSize(),
                        'status' => 'pending',
                    ]);
                }
            }

            // Exécution de la vérification de vivacité et de concordance faciale
            $verificationResult = $this->faceVerificationService->verify(
                idCardFront: $idFrontFile,
                selfieFront: $selfieFile,
                livenessFrames: $livenessFiles
            );

            $currentMetadata = $submission->metadata ?? [];
            $submission->metadata = array_merge($currentMetadata, [
                'face_match' => $verificationResult['face_match'],
                'liveness' => $verificationResult['liveness'],
            ]);
            $submission->save();

            try {
                $user->notify(new KycSubmittedNotification($submission));
            } catch (\Throwable $e) {
                Log::warning("Échec notification KYC soumission: {$e->getMessage()}");
            }

            return $submission->load(['documents']);
        });
    }

    /**
     * Valide l'intégrité et la conformité d'une pièce KYC.
     */
    protected function validateFile(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['Le document ne peut pas dépasser 10 Mo.'],
            ]);
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => ['Format de fichier non accepté (formats autorisés : JPG, PNG, WEBP, PDF).'],
            ]);
        }
    }

    /**
     * Récupère l'état KYC actuel de l'utilisateur.
     *
     * @return array<string, mixed>
     */
    public function getKycStatus(User $user): array
    {
        /** @var KycSubmission|null $latest */
        $latest = $user->latestKycSubmission()->with('documents')->first();

        return [
            'is_verified' => (bool) $user->is_verified,
            'verification_lapsed' => (bool) ($user->verification_lapsed ?? false),
            'latest_submission' => $latest,
            'has_pending_submission' => $latest && $latest->status === KycStatus::Pending,
        ];
    }

    /**
     * Téléchargement sécurisé d'un document KYC privé.
     * Réservé au propriétaire de la pièce ou à l'équipe conformité (rôle/permission conformité).
     * Chaque consultation est déchiffrée et journalisée dans AdminAuditLog.
     */
    public function downloadDocument(KycDocument $document, User $viewer): StreamedResponse
    {
        $isOwner = $document->user_id === $viewer->id;
        $isCompliance = $viewer->hasRole('verification')
            || $viewer->hasRole('direction')
            || $viewer->hasRole('compliance')
            || $viewer->hasPermission('kyc:view')
            || $viewer->hasPermission('kyc:documents')
            || $viewer->hasPermission('verification:view');

        if (! $isOwner && ! $isCompliance) {
            throw new AuthorizationException('Seule l\'équipe conformité BANGO est autorisée à consulter les pièces d\'identité.');
        }

        if (! Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'Fichier introuvable sur le stockage sécurisé.');
        }

        // Journalisation de la consultation si effectuée par la conformité ou un admin
        if (! $isOwner) {
            AdminAuditLog::logChainedEvent(
                event: 'kyc.document_opened',
                operator: $viewer,
                targetType: 'KycDocument',
                targetId: (string) $document->id,
                targetLabel: "Consultation pièce KYC {$document->kind?->value} ({$document->original_name}) de l'utilisateur #{$document->user_id}",
                context: [
                    'document_id' => $document->id,
                    'user_id' => $document->user_id,
                    'kind' => $document->kind?->value,
                    'original_name' => $document->original_name,
                    'mime_type' => $document->mime_type,
                ],
                ip: request()?->ip(),
                userAgent: request()?->userAgent()
            );
        }

        $rawContent = Storage::disk('local')->get($document->file_path);

        // Déchiffrement au vol si le contenu a été chiffré au repos (avec rétrocompatibilité en clair)
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
     * Ajoute un justificatif de propriété pour un bailleur déjà validé (ou en cours).
     */
    public function addPropertyDeed(User $user, UploadedFile $file, ?string $deedType = null, ?string $listingId = null): KycDocument
    {
        $this->validateFile($file);

        // Trouver la dernière soumission approuvée ou en cours du bailleur
        $submission = KycSubmission::where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        $submissionId = $submission?->id;

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $fileName = (string) Str::uuid().'.'.$extension;
        $privatePath = "kyc/{$user->id}/{$fileName}";

        $fileContent = file_get_contents($file->getRealPath());
        $encryptedContent = Crypt::encrypt($fileContent);
        Storage::disk('local')->put($privatePath, $encryptedContent);

        return KycDocument::create([
            'kyc_submission_id' => $submissionId,
            'user_id' => $user->id,
            'listing_id' => $listingId,
            'kind' => KycDocKind::Deed,
            'deed_type' => $deedType,
            'file_path' => $privatePath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'status' => 'pending',
        ]);
    }

    /**
     * Liste les justificatifs de propriété d'un bailleur.
     *
     * @return Collection<int, KycDocument>
     */
    public function listPropertyDeeds(User $user)
    {
        return KycDocument::where('user_id', $user->id)
            ->where('kind', KycDocKind::Deed)
            ->latest('created_at')
            ->get();
    }
}
