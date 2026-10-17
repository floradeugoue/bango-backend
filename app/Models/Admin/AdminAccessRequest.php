<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAccessRequest extends Model
{
    use HasFactory;

    protected $table = 'admin_access_requests';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'department',
        'requested_role',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
