<?php

namespace App\Models\Upload;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TusUploadSession extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'tus_upload_sessions';

    protected $fillable = [
        'id',
        'user_id',
        'upload_length',
        'upload_offset',
        'mime_type',
        'original_filename',
        'metadata',
        'status',
        'temp_path',
        'final_path',
        'final_url',
        'disk',
        'expires_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'upload_length' => 'integer',
            'upload_offset' => 'integer',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function getProgressAttribute(): float
    {
        if ($this->upload_length <= 0) {
            return 0.0;
        }

        return round(($this->upload_offset / $this->upload_length) * 100, 2);
    }
}
