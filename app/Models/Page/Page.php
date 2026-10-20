<?php

namespace App\Models\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Models\Boost\Campaign;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Database\Factories\Page\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pages';

    protected $fillable = [
        'owner_id',
        'name',
        'handle',
        'description',
        'sector',
        'logo_url',
        'cover_url',
        'email',
        'phone',
        'website',
        'address',
        'city',
        'neighbourhood',
        'certification_status',
        'status',
        'is_hidden',
        'followers_count',
        'inbox_assigned_to_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sector' => PageSector::class,
            'certification_status' => PageCertificationStatus::class,
            'status' => PageStatus::class,
            'is_hidden' => 'boolean',
            'followers_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Propriétaire initial et principal de la Page.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Tous les enregistrements de membres.
     */
    public function members(): HasMany
    {
        return $this->hasMany(PageMember::class);
    }

    /**
     * Membres actifs ayant accepté leur invitation.
     */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(PageMember::class)->where('status', PageMemberStatus::Accepted);
    }

    /**
     * Utilisateurs associés via la table pivot.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'page_members')
            ->withPivot(['id', 'role', 'status', 'invited_by_id', 'invited_at', 'accepted_at', 'revoked_at'])
            ->withTimestamps();
    }

    /**
     * Dossiers de certification déposés pour cette page.
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(PageCertification::class)->orderByDesc('created_at');
    }

    /**
     * Dernier dossier de certification soumis.
     */
    public function latestCertification(): HasOne
    {
        return $this->hasOne(PageCertification::class)->latestOfMany();
    }

    /**
     * Publications Square au nom de la Page.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Reels publiés au nom de la Page.
     */
    public function reels(): HasMany
    {
        return $this->hasMany(Reel::class);
    }

    /**
     * Conversations de l'inbox professionnelle.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Campagnes de Boost au nom de la Page.
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Vitrine immobilière des biens rattachés à la Page.
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * Membre ou agent délégué affecté à la gestion de la boîte de réception.
     */
    public function inboxAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inbox_assigned_to_id');
    }

    /**
     * Abonnés de la Page Professionnelle.
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'page_followers', 'page_id', 'user_id')->withTimestamps();
    }

    // ==========================================
    // Scopes Eloquent
    // ==========================================

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', PageStatus::Active)->where('is_hidden', false);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PageStatus::Active);
    }

    public function scopeCertified(Builder $query): Builder
    {
        return $query->where('certification_status', PageCertificationStatus::Approved);
    }

    public function scopeSector(Builder $query, string|PageSector $sector): Builder
    {
        $val = $sector instanceof PageSector ? $sector->value : $sector;

        return $query->where('sector', $val);
    }

    // ==========================================
    // Méthodes métier et vérifications RBAC
    // ==========================================

    public function isCertified(): bool
    {
        return $this->certification_status === PageCertificationStatus::Approved;
    }

    public function isFollowedBy(User $user): bool
    {
        if ($this->relationLoaded('followers')) {
            return $this->followers->contains('id', $user->id);
        }

        return $this->followers()->where('user_id', $user->id)->exists();
    }

    public function isActive(): bool
    {
        return $this->status === PageStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === PageStatus::Suspended;
    }

    public function isOwner(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        if ((int) $this->owner_id === (int) $userId) {
            return true;
        }

        return $this->memberRole($user) === PageRole::Owner;
    }

    public function activeMember(User|int $user): ?PageMember
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->members()
            ->where('user_id', $userId)
            ->where('status', PageMemberStatus::Accepted->value)
            ->first();
    }

    public function memberRole(User|int $user): ?PageRole
    {
        $member = $this->activeMember($user);
        if ($member) {
            return $member->role;
        }

        $userId = $user instanceof User ? $user->id : $user;
        if ((int) $this->owner_id === (int) $userId) {
            return PageRole::Owner;
        }

        return null;
    }

    public function hasMember(User|int $user, ?PageRole $role = null): bool
    {
        $member = $this->activeMember($user);
        if (! $member) {
            return false;
        }

        if ($role === null) {
            return true;
        }

        return $member->role === $role;
    }

    public function canUserPublish(User|int $user): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        $role = $this->memberRole($user);

        return in_array($role, [PageRole::Owner, PageRole::Editor], true);
    }

    public function canUserAccessInbox(User|int $user): bool
    {
        $role = $this->memberRole($user);

        return in_array($role, [PageRole::Owner, PageRole::Editor, PageRole::Moderator], true);
    }

    public function canUserManageMembers(User|int $user): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        return $this->memberRole($user) === PageRole::Owner;
    }

    public function canUserManageFinances(User|int $user): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        return $this->memberRole($user) === PageRole::Owner;
    }
}
