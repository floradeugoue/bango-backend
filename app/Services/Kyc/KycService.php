<?php

namespace App\Services\Kyc;

use App\Enums\Kyc\KycDocKind;
use App\Enums\Kyc\KycStatus;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycSubmission;
use App\Models\User;
use App\Notifications\Kyc\KycSubmittedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
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
            $submission = KycSubmission::create([
                'user_id' => $user->id,
                'status' => KycStatus::Pending,
                'full_name' => $data['full_name'] ?? $user->name,
                'id_type' => $data['id_type'] ?? null,
                'id_number' => $data['id_number'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'property_right' => $data['property_right'] ?? null,
                'deed_type' => $data['deed_type'] ?? null,
            ]);

            foreach ($files as $key => $fileOrArray) {
                $fileList = is_array($fileOrArray) ? $fileOrArray : [$fileOrArray];

                foreach ($fileList as $file) {
                    if (! ($file instanceof UploadedFile)) {
                        continue;
                    }

                    $this->validateFile($file);

                    $kind = KycDocKind::tryFrom($key) ?? match ($key) {
                        'identity_front', 'front' => KycDocKind::Front,
                        'identity_back', 'back' => KycDocKind::Back,
                        'selfie' => KycDocKind::Selfie,
                        'deed', 'property_deed' => KycDocKind::Deed,
                        default => KycDocKind::Other,
                    };

                    $extension = $file->getClientOriginalExtension() ?: 'bin';
                    $fileName = (string) Str::uuid().'.'.$extension;
                    $privatePath = "kyc/{$user->id}/{$fileName}";

                    Storage::disk('local')->putFileAs("kyc/{$user->id}", $file, $fileName);

                    KycDocument::create([
                        'kyc_submission_id' => $submission->id,
                        'user_id' => $user->id,
                        'kind' => $kind,
                        'file_path' => $privatePath,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'file_size' => $file->getSize(),
                        'status' => 'pending',
                    ]);
                }
            }

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
     */
    public function downloadDocument(KycDocument $document, User $viewer): StreamedResponse
    {
        if ($document->user_id !== $viewer->id && ! $viewer->isAdmin()) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à consulter ce document.');
        }

        if (! Storage::disk('local')->exists($document->file_path)) {
            abort(404, 'Fichier introuvable sur le stockage sécurisé.');
        }

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }
}
