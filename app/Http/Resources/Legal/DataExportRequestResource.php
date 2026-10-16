<?php

namespace App\Http\Resources\Legal;

use App\Models\Legal\DataExportRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DataExportRequest
 */
class DataExportRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $downloadUrl = $this->isDownloadable()
            ? url('/api/user/data-export/download/'.$this->id)
            : null;

        return [
            'id' => (int) $this->id,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'file_size_bytes' => $this->file_size_bytes,
            'download_url' => $downloadUrl,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
