<?php

namespace App\Services\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentServiceType;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Notifications\Page\PageCertificationApprovedNotification;
use App\Notifications\Page\PageCertificationRejectedNotification;
use App\Notifications\Page\PageCertificationSubmittedNotification;
use App\Notifications\Page\PageCertificationSupplementRequestedNotification;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PricingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PageCertificationService
{
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function __construct(
        protected PaymentEngineService $paymentEngineService,
        protected PricingService $pricingService
    ) {}

    /**
     * Soumet un dossier de certification entreprise avec téléversement sécurisé non-public.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile>  $files
     */
    public function submit(Page $page, User $user, array $data, array $files): PageCertification
    {
        if ($page->isCertified()) {
            throw ValidationException::withMessages([
                'page' => ['Cette page est déjà officiellement certifiée.'],
            ]);
        }

        if ($page->certification_status === PageCertificationStatus::Pending) {
            throw ValidationException::withMessages([
                'page' => ['Un dossier de certification est déjà en cours d\'examen pour cette page.'],
            ]);
        }

        if (empty($files)) {
            throw ValidationException::withMessages([
                'documents' => ['Veuillez fournir au moins un justificatif officiel (RCCM, NIF ou pièce d\'identité).'],
            ]);
        }

        return DB::transaction(function () use ($page, $user, $data, $files) {
            /** @var Page $lockedPage */
            $lockedPage = Page::where('id', $page->id)->lockForUpdate()->firstOrFail();

            $storedDocuments = [];
            foreach ($files as $key => $file) {
                if ($file instanceof UploadedFile) {
                    $this->validateFile($file);
                    $path = $file->store("page_certifications/{$lockedPage->id}", 'local');
                    $storedDocuments[$key] = [
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                    ];
                }
            }

            /** @var PageCertification $certification */
            $certification = PageCertification::create([
                'page_id' => $lockedPage->id,
                'submitted_by_id' => $user->id,
                'status' => PageCertificationStatus::Pending,
                'rccm_number' => $data['rccm_number'] ?? null,
                'nif_number' => $data['nif_number'] ?? null,
                'legal_representative_name' => $data['legal_representative_name'] ?? null,
                'legal_representative_id_type' => $data['legal_representative_id_type'] ?? null,
                'legal_representative_id_number' => $data['legal_representative_id_number'] ?? null,
                'documents' => $storedDocuments,
                'corrections_count' => 0,
                'submitted_at' => now(),
            ]);

            $certification->recordHistory('submitted', $user->id, 'Soumission initiale du dossier');
            $certification->save();

            $lockedPage->certification_status = PageCertificationStatus::Pending;
            $lockedPage->save();

            // Notification de soumission
            $user->notify(new PageCertificationSubmittedNotification($certification));

            return $certification->fresh(['page', 'submitter']);
        });
    }

    /**
     * Initie le paiement des frais de certification via le module PAY existant.
     *
     * @param  array<string, mixed>  $paymentData
     */
    public function initiatePayment(Page $page, User $user, array $paymentData): PaymentTransaction
    {
        if (! $page->isOwner($user) && ! $user->isAdmin()) {
            throw new AuthorizationException('Seul le propriétaire de la page peut initier le paiement des frais de certification.');
        }

        $certification = $page->latestCertification;
        if (! $certification) {
            throw ValidationException::withMessages([
                'certification' => ['Veuillez d\'abord soumettre le dossier de certification avant de régler les frais.'],
            ]);
        }

        $priceId = isset($paymentData['price_id']) ? (int) $paymentData['price_id'] : null;
        $quote = $this->pricingService->calculateQuote(
            serviceType: PaymentServiceType::Certification,
            priceId: $priceId,
            gateway: $paymentData['gateway'] ?? 'monetbil'
        );

        $idempotencyKey = $paymentData['idempotency_key'] ?? ('cert_pay_'.$certification->id.'_'.Str::random(8));

        $transaction = $this->paymentEngineService->createOrRetrieveTransaction(
            user: $user,
            quote: $quote,
            idempotencyKey: $idempotencyKey,
            context: [
                'phone' => $paymentData['phone_number'] ?? null,
                'operator' => $paymentData['provider'] ?? null,
                'provider' => $paymentData['provider'] ?? null,
                'channel' => PaymentChannel::MobileMoney->value,
                'page_id' => $page->id,
                'certification_id' => $certification->id,
            ]
        );

        $transaction->update([
            'payable_type' => PageCertification::class,
            'payable_id' => $certification->id,
        ]);

        return $transaction;
    }

    /**
     * Fulfiller appelé par le module PAY lors de la confirmation d'un paiement.
     * Idempotent : ne certifie JAMAIS automatiquement la page !
     */
    public function confirmPaymentFromTransaction(PaymentTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            /** @var PageCertification|null $certification */
            $certification = PageCertification::where('id', $transaction->payable_id)
                ->lockForUpdate()
                ->first();

            if (! $certification) {
                return;
            }

            if ($certification->payment_transaction_id === $transaction->id) {
                return; // Déjà traité de façon idempotente
            }

            $certification->payment_transaction_id = $transaction->id;
            $certification->recordHistory(
                'payment_confirmed',
                null,
                "Frais de certification réglés avec succès (Réf: {$transaction->reference})"
            );
            $certification->save();
        });
    }

    /**
     * Soumet une correction au dossier (règle PG-07 : 2 corrections max incluses).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, UploadedFile>  $files
     */
    public function resubmitCorrection(PageCertification $certification, User $user, array $data, array $files): PageCertification
    {
        if (! $certification->canSubmitCorrection()) {
            throw ValidationException::withMessages([
                'corrections' => [
                    sprintf('Le quota de %d corrections incluses dans le processus de certification a été atteint.', PageCertification::MAX_ALLOWED_CORRECTIONS),
                ],
            ]);
        }

        return DB::transaction(function () use ($certification, $user, $data, $files) {
            /** @var PageCertification $lockedCert */
            $lockedCert = PageCertification::where('id', $certification->id)->lockForUpdate()->firstOrFail();

            $existingDocs = $lockedCert->documents ?? [];
            foreach ($files as $key => $file) {
                if ($file instanceof UploadedFile) {
                    $this->validateFile($file);
                    $path = $file->store("page_certifications/{$lockedCert->page_id}", 'local');
                    $existingDocs[$key] = [
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                    ];
                }
            }

            $lockedCert->documents = $existingDocs;
            $lockedCert->status = PageCertificationStatus::Pending;
            $lockedCert->corrections_count += 1;
            $lockedCert->submitted_at = now();
            $lockedCert->rejection_reason = null;
            $lockedCert->supplement_request_reason = null;

            if (isset($data['rccm_number'])) {
                $lockedCert->rccm_number = $data['rccm_number'];
            }
            if (isset($data['nif_number'])) {
                $lockedCert->nif_number = $data['nif_number'];
            }
            if (isset($data['legal_representative_name'])) {
                $lockedCert->legal_representative_name = $data['legal_representative_name'];
            }

            $lockedCert->recordHistory(
                'correction_resubmitted',
                $user->id,
                "Correction n°{$lockedCert->corrections_count} soumise suite aux demandes de l'administration"
            );
            $lockedCert->save();

            // Remettre la page en pending
            $lockedCert->page->certification_status = PageCertificationStatus::Pending;
            $lockedCert->page->save();

            $user->notify(new PageCertificationSubmittedNotification($lockedCert));

            return $lockedCert->fresh(['page', 'submitter']);
        });
    }

    /**
     * Approbation administrative de la certification.
     */
    public function adminApprove(PageCertification $certification, User $admin): PageCertification
    {
        return DB::transaction(function () use ($certification, $admin) {
            /** @var PageCertification $lockedCert */
            $lockedCert = PageCertification::where('id', $certification->id)->lockForUpdate()->firstOrFail();

            $lockedCert->status = PageCertificationStatus::Approved;
            $lockedCert->decided_at = now();
            $lockedCert->decided_by_id = $admin->id;
            $lockedCert->recordHistory('approved', $admin->id, 'Dossier de certification validé et certifié');
            $lockedCert->save();

            $lockedCert->page->certification_status = PageCertificationStatus::Approved;
            $lockedCert->page->save();

            $lockedCert->page->owner->notify(new PageCertificationApprovedNotification($lockedCert));

            return $lockedCert->fresh(['page', 'reviewer']);
        });
    }

    /**
     * Refus administratif de la certification.
     */
    public function adminReject(PageCertification $certification, User $admin, string $reason): PageCertification
    {
        return DB::transaction(function () use ($certification, $admin, $reason) {
            /** @var PageCertification $lockedCert */
            $lockedCert = PageCertification::where('id', $certification->id)->lockForUpdate()->firstOrFail();

            $lockedCert->status = PageCertificationStatus::Rejected;
            $lockedCert->rejection_reason = $reason;
            $lockedCert->decided_at = now();
            $lockedCert->decided_by_id = $admin->id;
            $lockedCert->recordHistory('rejected', $admin->id, $reason);
            $lockedCert->save();

            $lockedCert->page->certification_status = PageCertificationStatus::Rejected;
            $lockedCert->page->save();

            $lockedCert->page->owner->notify(new PageCertificationRejectedNotification($lockedCert));

            return $lockedCert->fresh(['page', 'reviewer']);
        });
    }

    /**
     * Demande administrative de pièces complémentaires.
     */
    public function adminRequestSupplement(PageCertification $certification, User $admin, string $reason): PageCertification
    {
        return DB::transaction(function () use ($certification, $admin, $reason) {
            /** @var PageCertification $lockedCert */
            $lockedCert = PageCertification::where('id', $certification->id)->lockForUpdate()->firstOrFail();

            $lockedCert->status = PageCertificationStatus::SupplementRequested;
            $lockedCert->supplement_request_reason = $reason;
            $lockedCert->decided_at = now();
            $lockedCert->decided_by_id = $admin->id;
            $lockedCert->recordHistory('supplement_requested', $admin->id, $reason);
            $lockedCert->save();

            $lockedCert->page->certification_status = PageCertificationStatus::SupplementRequested;
            $lockedCert->page->save();

            $lockedCert->page->owner->notify(new PageCertificationSupplementRequestedNotification($lockedCert));

            return $lockedCert->fresh(['page', 'reviewer']);
        });
    }

    /**
     * Suspension de la certification (EDGE-03).
     */
    public function adminSuspendCertification(Page $page, User $admin, string $reason): void
    {
        DB::transaction(function () use ($page, $admin, $reason) {
            /** @var Page $lockedPage */
            $lockedPage = Page::where('id', $page->id)->lockForUpdate()->firstOrFail();
            $lockedPage->certification_status = PageCertificationStatus::Suspended;
            $lockedPage->save();

            /** @var PageCertification|null $latestCert */
            $latestCert = $lockedPage->certifications()->lockForUpdate()->first();
            if ($latestCert) {
                $latestCert->status = PageCertificationStatus::Suspended;
                $latestCert->recordHistory('suspended', $admin->id, $reason);
                $latestCert->save();
            }
        });
    }

    /**
     * Téléchargement sécurisé d'un document justificatif privé.
     */
    public function downloadDocument(PageCertification $certification, string $docKey, User $viewer): StreamedResponse
    {
        $docs = $certification->documents ?? [];
        if (! isset($docs[$docKey])) {
            abort(404, 'Document introuvable dans ce dossier.');
        }

        $filePath = $docs[$docKey]['file_path'] ?? null;
        $originalName = $docs[$docKey]['original_name'] ?? 'document.pdf';

        if (! $filePath || ! Storage::disk('local')->exists($filePath)) {
            abort(404, 'Fichier introuvable sur le stockage sécurisé.');
        }

        return Storage::disk('local')->download($filePath, $originalName);
    }

    /**
     * Annule la demande de certification en cours tant qu'elle est au statut pending (frais non remboursés).
     */
    public function cancelPending(Page $page, User $user): void
    {
        if (! $page->isOwner($user) && ! $user->isAdmin()) {
            throw new AuthorizationException('Seul le propriétaire de la page peut annuler cette demande de certification.');
        }

        DB::transaction(function () use ($page, $user) {
            /** @var Page $lockedPage */
            $lockedPage = Page::where('id', $page->id)->lockForUpdate()->firstOrFail();

            /** @var PageCertification|null $pendingCert */
            $pendingCert = PageCertification::where('page_id', $lockedPage->id)
                ->where('status', PageCertificationStatus::Pending)
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $pendingCert) {
                throw ValidationException::withMessages([
                    'certification' => ['Aucune demande de certification en attente (pending) ne peut être annulée.'],
                ]);
            }

            $pendingCert->recordHistory(
                'cancelled_by_user',
                $user->id,
                'Demande de certification annulée par le demandeur (frais non remboursés).'
            );
            $pendingCert->delete(); // Soft-delete du dossier

            // Réinitialiser le statut de certification de la page si elle était pending
            $hasOtherApproved = PageCertification::where('page_id', $lockedPage->id)
                ->where('status', PageCertificationStatus::Approved)
                ->exists();

            $lockedPage->certification_status = $hasOtherApproved
                ? PageCertificationStatus::Approved
                : PageCertificationStatus::None;
            $lockedPage->save();
        });
    }

    protected function validateFile(UploadedFile $file): void
    {
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => ['La taille maximale autorisée par justificatif est de 10 Mo.'],
            ]);
        }

        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw ValidationException::withMessages([
                'file' => ['Format de fichier non accepté (formats autorisés : JPG, PNG, WEBP, PDF).'],
            ]);
        }
    }
}
