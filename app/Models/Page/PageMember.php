<?php

namespace App\Models\Page;

use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Models\User;
use Database\Factories\Page\PageMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageMember extends Model
{
    /** @use HasFactory<PageMemberFactory> */
    use HasFactory;

    protected $table = 'page_members';

    protected $fillable = [
        'page_id',
        'user_id',
        'role',
        'status',
        'invited_by_id',
        'invited_at',
        'accepted_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => PageRole::class,
            'status' => PageMemberStatus::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isOwner(): bool
    {
        return $this->role === PageRole::Owner;
    }

    public function isEditor(): bool
    {
        return $this->role === PageRole::Editor;
    }

    public function isModerator(): bool
    {
        return $this->role === PageRole::Moderator;
    }

    public function isActive(): bool
    {
        return $this->status === PageMemberStatus::Accepted;
    }

    public function isRevoked(): bool
    {
        return $this->status === PageMemberStatus::Revoked;
    }

    public function isPending(): bool
    {
        return $this->status === PageMemberStatus::Pending;
    }
}
