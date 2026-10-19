<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditTamperAttempt extends Model
{
    use HasFactory;

    protected $table = 'admin_audit_tamper_attempts';

    public $timestamps = false;

    protected $fillable = [
        'audit_log_id',
        'operator_id',
        'attempted_changes',
        'ip_address',
        'user_agent',
        'detection_source',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempted_changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AdminAuditLog::class, 'audit_log_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
