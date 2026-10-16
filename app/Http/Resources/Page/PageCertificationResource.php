<?php

namespace App\Http\Resources\Page;

use App\Enums\Page\PageRole;
use App\Models\Page\PageCertification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PageCertification
 */
class PageCertificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwnerOrAdmin = $viewer && ($viewer->isAdmin() || $this->page->memberRole($viewer) === PageRole::Owner);

        $documentsMetadata = [];
        if ($isOwnerOrAdmin && is_array($this->documents)) {
            foreach ($this->documents as $key => $doc) {
                $documentsMetadata[$key] = [
                    'original_name' => $doc['original_name'] ?? 'document.pdf',
                    'mime_type' => $doc['mime_type'] ?? 'application/pdf',
                    'file_size' => $doc['file_size'] ?? 0,
                    'download_url' => route('api.pages.certifications.documents.download', [
                        'certification' => $this->id,
                        'key' => $key,
                    ]),
                ];
            }
        }

        return [
            'id' => (string) $this->id,
            'page_id' => (int) $this->page_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'rccm_number' => $isOwnerOrAdmin ? $this->rccm_number : null,
            'nif_number' => $isOwnerOrAdmin ? $this->nif_number : null,
            'legal_representative_name' => $isOwnerOrAdmin ? $this->legal_representative_name : null,
            'documents' => $documentsMetadata,
            'corrections_count' => (int) $this->corrections_count,
            'max_allowed_corrections' => PageCertification::MAX_ALLOWED_CORRECTIONS,
            'remaining_corrections' => max(0, PageCertification::MAX_ALLOWED_CORRECTIONS - (int) $this->corrections_count),
            'can_submit_correction' => $this->canSubmitCorrection(),
            'rejection_reason' => $this->rejection_reason,
            'supplement_request_reason' => $this->supplement_request_reason,
            'payment_transaction_id' => $isOwnerOrAdmin ? $this->payment_transaction_id : null,
            'is_paid' => $this->payment_transaction_id !== null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'history' => $isOwnerOrAdmin ? ($this->history ?? []) : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
