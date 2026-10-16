<?php

namespace App\Models\Legal;

use App\Enums\Legal\DataExportStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExportRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'file_path',
        'disk',
        'file_size_bytes',
        'expires_at',
        'completed_at',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DataExportStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'file_size_bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vérifie si l'archive est prête et encore téléchargeable.
     */
    public function isDownloadable(): bool
    {
        return $this->status === DataExportStatus::Ready &&
            $this->expires_at !== null &&
            $this->expires_at->isFuture() &&
            ! empty($this->file_path);
    }
}
