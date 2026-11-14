<?php

namespace App\Services\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Http\Resources\Page\PageCertificationResource;
use App\Http\Resources\Page\PageResource;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Square\Post;
use App\Models\Support\Report;
use App\Models\User;
use App\Notifications\Page\PageSuspendedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PageService
{
    /**
     * Crée une nouvelle Page Professionnelle avec son propriétaire initial.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $owner, array $data, ?UploadedFile $logo = null, ?UploadedFile $cover = null): Page
    {
        return DB::transaction(function () use ($owner, $data, $logo, $cover) {
            $name = trim($data['name']);
            $hasExplicitHandle = isset($data['handle']) && Str::slug($data['handle']) !== '';
            $handle = $hasExplicitHandle ? Str::slug($data['handle']) : Str::slug($name);

            if ($hasExplicitHandle) {
                // Identifiant choisi par l'utilisateur : jamais renommé en silence
                if (Page::withTrashed()->where('handle', $handle)->exists()) {
                    throw ValidationException::withMessages(['handle' => ['handle taken']]);
                }
            } else {
                // Identifiant dérivé du nom : suffixe incrémental en cas de collision
                $baseHandle = $handle;
                $count = 1;
                while (Page::withTrashed()->where('handle', $handle)->exists()) {
                    $handle = "{$baseHandle}-{$count}";
                    $count++;
                }
            }

            $logoUrl = null;
            if ($logo) {
                $path = $logo->store('pages/logos', 'public');
                $logoUrl = Storage::disk('public')->url($path);
            }

            $coverUrl = null;
            if ($cover) {
                $path = $cover->store('pages/covers', 'public');
                $coverUrl = Storage::disk('public')->url($path);
            }

            $sector = $data['sector'] ?? PageSector::Other->value;
            if ($sector instanceof PageSector) {
                $sector = $sector->value;
            }

            /** @var Page $page */
            $page = Page::create([
                'owner_id' => $owner->id,
                'name' => $name,
                'handle' => $handle,
                'description' => $data['description'] ?? null,
                'sector' => $sector,
                'logo_url' => $logoUrl ?? ($data['logo_url'] ?? null),
                'cover_url' => $coverUrl ?? ($data['cover_url'] ?? null),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'website' => $data['website'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'neighbourhood' => $data['neighbourhood'] ?? null,
                'certification_status' => PageCertificationStatus::None,
                'status' => PageStatus::Active,
                'metadata' => $data['metadata'] ?? null,
            ]);

            // Enregistrement automatique du créateur comme Membre Propriétaire accepté
            PageMember::create([
                'page_id' => $page->id,
                'user_id' => $owner->id,
                'role' => PageRole::Owner,
                'status' => PageMemberStatus::Accepted,
                'invited_by_id' => null,
                'invited_at' => now(),
                'accepted_at' => now(),
            ]);

            return $page->load(['owner', 'activeMembers.user']);
        });
    }

    /**
     * Met à jour les informations d'une Page.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Page $page, array $data, ?UploadedFile $logo = null, ?UploadedFile $cover = null): Page
    {
        return DB::transaction(function () use ($page, $data, $logo, $cover) {
            /** @var Page $lockedPage */
            $lockedPage = Page::where('id', $page->id)->lockForUpdate()->firstOrFail();

            if ($logo) {
                $path = $logo->store('pages/logos', 'public');
                $lockedPage->logo_url = Storage::disk('public')->url($path);
            } elseif (array_key_exists('logo_url', $data)) {
                $lockedPage->logo_url = $data['logo_url'];
            }

            if ($cover) {
                $path = $cover->store('pages/covers', 'public');
                $lockedPage->cover_url = Storage::disk('public')->url($path);
            } elseif (array_key_exists('cover_url', $data)) {
                $lockedPage->cover_url = $data['cover_url'];
            }

            if (isset($data['name'])) {
                $lockedPage->name = trim($data['name']);
            }

            if (isset($data['handle'])) {
                $newHandle = Str::slug($data['handle']);
                if ($newHandle !== $lockedPage->handle) {
                    $exists = Page::where('handle', $newHandle)->where('id', '!=', $lockedPage->id)->exists();
                    if ($exists) {
                        throw ValidationException::withMessages([
                            'handle' => ['Cet identifiant public (handle) est déjà utilisé.'],
                        ]);
                    }
                    $lockedPage->handle = $newHandle;
                }
            }

            if (array_key_exists('description', $data)) {
                $lockedPage->description = $data['description'];
            }

            if (isset($data['sector'])) {
                $sector = $data['sector'] instanceof PageSector ? $data['sector']->value : $data['sector'];
                $lockedPage->sector = $sector;
            }

            foreach (['email', 'phone', 'website', 'address', 'city', 'neighbourhood'] as $field) {
                if (array_key_exists($field, $data)) {
                    $lockedPage->{$field} = $data[$field];
                }
            }

            if (array_key_exists('metadata', $data)) {
                $lockedPage->metadata = $data['metadata'];
            }

            $lockedPage->save();

            return $lockedPage->fresh(['owner', 'activeMembers.user']);
        });
    }

    /**
     * Transfère la propriété de la Page à un autre utilisateur (EDGE-01).
     */
    public function transferOwnership(Page $page, User $currentOwner, User $newOwner): Page
    {
        if ((int) $currentOwner->id === (int) $newOwner->id) {
            throw ValidationException::withMessages([
                'new_owner_id' => ['Vous êtes déjà le propriétaire de cette page.'],
            ]);
        }

        return DB::transaction(function () use ($page, $currentOwner, $newOwner) {
            /** @var Page $lockedPage */
            $lockedPage = Page::where('id', $page->id)->lockForUpdate()->firstOrFail();

            // 1. Mise à jour ou création de l'adhésion du nouveau propriétaire
            /** @var PageMember $newOwnerMember */
            $newOwnerMember = PageMember::firstOrNew([
                'page_id' => $lockedPage->id,
                'user_id' => $newOwner->id,
            ]);

            $newOwnerMember->role = PageRole::Owner;
            $newOwnerMember->status = PageMemberStatus::Accepted;
            $newOwnerMember->accepted_at = $newOwnerMember->accepted_at ?? now();
            $newOwnerMember->revoked_at = null;
            $newOwnerMember->save();

            // 2. Rétrogradation de l'ancien propriétaire en Éditeur
            $oldOwnerMember = PageMember::where('page_id', $lockedPage->id)
                ->where('user_id', $currentOwner->id)
                ->lockForUpdate()
                ->first();

            if ($oldOwnerMember) {
                $oldOwnerMember->role = PageRole::Editor;
                $oldOwnerMember->save();
            }

            // 3. Mise à jour de la clé propriétaire de la Page
            $lockedPage->owner_id = $newOwner->id;
            $lockedPage->save();

            return $lockedPage->fresh(['owner', 'activeMembers.user']);
        });
    }

    /**
     * Bascule le contexte actif de l'application vers une Page Pro (PG-04).
     *
     * @return array<string, mixed>
     */
    public function switchContext(User $user, Page $page): array
    {
        $member = $page->activeMember($user);

        if (! $member && ! $user->isAdmin()) {
            throw ValidationException::withMessages([
                'page' => ['Vous n\'êtes pas un membre actif de cette page.'],
            ]);
        }

        $role = $member ? $member->role : ($user->isAdmin() ? PageRole::Owner : PageRole::Moderator);

        return [
            'context_type' => 'page',
            'page' => [
                'id' => (int) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'cover_url' => $page->cover_url,
                'sector' => $page->sector->value,
                'is_certified' => $page->isCertified(),
                'status' => $page->status->value,
            ],
            'member_role' => $role->value,
            'permissions' => [
                'can_edit' => $role->canEditDetails(),
                'can_publish' => $role->canPublishContent() && $page->isActive(),
                'can_manage_members' => $role->canManageMembers() && $page->isActive(),
                'can_manage_finances' => $role->canManageFinances() && $page->isActive(),
                'can_access_inbox' => $role->canAccessInbox(),
            ],
            'switched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Suspend une page professionnellement (EDGE-04).
     */
    public function suspendPage(Page $page, User $admin, ?string $reason = null): Page
    {
        return DB::transaction(function () use ($page, $admin, $reason) {
            /** @var Page $lockedPage */
            $lockedPage = Page::where('id', $page->id)->lockForUpdate()->firstOrFail();
            $lockedPage->status = PageStatus::Suspended;

            $meta = $lockedPage->metadata ?? [];
            $meta['suspension'] = [
                'suspended_at' => now()->toIso8601String(),
                'suspended_by_id' => $admin->id,
                'reason' => $reason,
            ];
            $lockedPage->metadata = $meta;
            $lockedPage->save();

            // Notifier le propriétaire
            $lockedPage->owner->notify(new PageSuspendedNotification($lockedPage, $reason));

            return $lockedPage->fresh();
        });
    }

    /**
     * Lève la suspension d'une page.
     */
    public function unsuspendPage(Page $page, User $admin): Page
    {
        return DB::transaction(function () use ($page, $admin) {
            /** @var Page $lockedPage */
            $lockedPage = Page::where('id', $page->id)->lockForUpdate()->firstOrFail();
            $lockedPage->status = PageStatus::Active;

            $meta = $lockedPage->metadata ?? [];
            $meta['suspension']['lifted_at'] = now()->toIso8601String();
            $meta['suspension']['lifted_by_id'] = $admin->id;
            $lockedPage->metadata = $meta;
            $lockedPage->save();

            return $lockedPage->fresh();
        });
    }

    /**
     * Supprime une page avec conservation d'historique (EDGE-06).
     */
    public function deletePage(Page $page, User $actor): void
    {
        DB::transaction(function () use ($page) {
            $page->delete();
        });
    }

    /**
     * Liste paginée des pages professionnelles pour l'administration.
     *
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator, counts: array<string, int>}
     */
    public function listAdminPages(array $filters = [], int $perPage = 20): array
    {
        $perPage = min(max($perPage, 1), 100);

        $query = Page::query()->withTrashed();

        // 1. Recherche textuelle multi-champs
        $search = $filters['q'] ?? $filters['search'] ?? null;
        if (! empty($search)) {
            $term = trim((string) $search);
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function (Builder $sub) use ($term, $like) {
                $sub->where('name', $like, "%{$term}%")
                    ->orWhere('handle', $like, "%{$term}%")
                    ->orWhere('email', $like, "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('city', $like, "%{$term}%")
                    ->orWhere('description', $like, "%{$term}%")
                    ->orWhereHas('owner', function (Builder $uq) use ($term, $like) {
                        $uq->where('name', $like, "%{$term}%")
                            ->orWhere('display_name', $like, "%{$term}%")
                            ->orWhere('email', $like, "%{$term}%")
                            ->orWhere('handle', $like, "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%");
                    });
            });
        }

        // 2. Filtre par ville
        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        // 3. Filtre par secteur
        if (! empty($filters['sector'])) {
            $sector = $filters['sector'];
            if ($sector instanceof PageSector) {
                $query->where('sector', $sector->value);
            } else {
                $query->where('sector', (string) $sector);
            }
        }

        // 4. Compteurs d'onglets calculés avant application du filtre de statut/certification (avec reorder)
        $countQuery = (clone $query)->reorder();
        $counts = [
            'all' => (clone $countQuery)->count(),
            'active' => (clone $countQuery)->whereNull('deleted_at')->where('status', PageStatus::Active->value)->count(),
            'suspended' => (clone $countQuery)->whereNull('deleted_at')->where('status', PageStatus::Suspended->value)->count(),
            'certified' => (clone $countQuery)->whereNull('deleted_at')->where('certification_status', PageCertificationStatus::Approved->value)->count(),
            'pending_certification' => (clone $countQuery)->whereNull('deleted_at')->where('certification_status', PageCertificationStatus::Pending->value)->count(),
            'deleted' => (clone $countQuery)->onlyTrashed()->count(),
        ];

        // 5. Filtre par statut opérationnel (active, suspended, deleted)
        if (! empty($filters['status'])) {
            if ($filters['status'] === 'deleted' || $filters['status'] === 'trashed') {
                $query->onlyTrashed();
            } else {
                $query->withoutTrashed()->where('status', $filters['status']);
            }
        } else {
            $query->withoutTrashed();
        }

        // 6. Filtre par statut de certification
        $certStatus = $filters['certification'] ?? $filters['certification_status'] ?? null;
        if (! empty($certStatus)) {
            $query->where('certification_status', $certStatus);
        }

        // 7. Eager loading et sous-requêtes de décompte pour la pagination
        $query->with([
            'owner:id,name,display_name,handle,email,phone,avatar_url',
        ])
            ->withCount(['activeMembers as members_count', 'posts', 'listings', 'followers']);

        // 8. Tri
        $sort = $filters['sort'] ?? 'created_at';
        $order = strtolower((string) ($filters['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'updated_at', 'name', 'city', 'sector', 'status', 'certification_status'];

        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $order);
        } else {
            $query->orderByDesc('created_at');
        }

        return [
            'paginator' => $query->paginate($perPage),
            'counts' => $counts,
        ];
    }

    /**
     * Fiche détaillée complète d'une page pro pour l'administration.
     *
     * @return array<string, mixed>
     */
    public function getAdminPageDetail(int|string $pageIdOrHandle): array
    {
        /** @var Page $page */
        $page = Page::withTrashed()
            ->where(function (Builder $q) use ($pageIdOrHandle) {
                if (is_numeric($pageIdOrHandle)) {
                    $q->where('id', (int) $pageIdOrHandle)->orWhere('handle', (string) $pageIdOrHandle);
                } else {
                    $q->where('handle', (string) $pageIdOrHandle);
                }
            })
            ->firstOrFail();

        // 1. Propriétaire
        $owner = $page->owner()->withTrashed()->first();

        // 2. Équipe et rôles
        $members = $page->members()
            ->with(['user' => fn ($uq) => $uq->withTrashed()->select(['id', 'name', 'display_name', 'handle', 'email', 'phone', 'avatar_url', 'badge', 'status'])])
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (PageMember $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'role' => $m->role->value,
                'role_label' => $m->role->label(),
                'status' => $m->status->value,
                'joined_at' => $m->joined_at?->toIso8601String() ?? $m->created_at?->toIso8601String(),
                'user' => $m->user ? [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'display_name' => $m->user->display_name ?: $m->user->name,
                    'handle' => $m->user->handle,
                    'email' => $m->user->email,
                    'phone' => $m->user->phone,
                    'avatar_url' => $m->user->avatar_url,
                    'badge' => $m->user->badge,
                    'status' => $m->user->status,
                ] : null,
            ]);

        // 3. Publications récentes
        $posts = $page->posts()
            ->withCount(['likes', 'comments'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Post $p) => [
                'id' => $p->id,
                'content' => $p->body ?? $p->content ?? null,
                'type' => $p->type ?? 'post',
                'media' => $p->media,
                'status' => $p->status,
                'visibility' => $p->visibility,
                'likes_count' => (int) ($p->likes_count ?? 0),
                'comments_count' => (int) ($p->comments_count ?? 0),
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        // 4. Signalements déposés contre la page
        $reports = Report::query()
            ->where(function (Builder $q) {
                $q->where('reportable_type', Page::class)
                    ->orWhere('reportable_type', 'page');
            })
            ->where('reportable_id', (string) $page->id)
            ->with(['reporter:id,name,display_name,email,handle'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Report $r) => [
                'id' => $r->id,
                'reason' => $r->reason instanceof \BackedEnum ? $r->reason->value : (string) $r->reason,
                'status' => $r->status instanceof \BackedEnum ? $r->status->value : (string) $r->status,
                'notes' => $r->notes ?? $r->description ?? null,
                'reporter' => $r->reporter ? [
                    'id' => $r->reporter->id,
                    'name' => $r->reporter->display_name ?: $r->reporter->name,
                    'handle' => $r->reporter->handle,
                    'email' => $r->reporter->email,
                ] : null,
                'created_at' => $r->created_at?->toIso8601String(),
            ]);

        // 5. Certification (dernier dossier et historique)
        $latestCert = $page->latestCertification()->with(['reviewer:id,name,email', 'paymentTransaction'])->first();
        $allCerts = $page->certifications()->with(['reviewer:id,name,email', 'paymentTransaction'])->orderByDesc('created_at')->get();

        // 6. Abonnement (actif et historique)
        $activeSub = $page->activeSubscription()->with(['product', 'price'])->first();
        $allSubs = $page->subscriptions()->with(['product', 'price'])->orderByDesc('created_at')->get();

        // 7. Statistiques globales
        $stats = [
            'followers_count' => $page->followers()->count(),
            'posts_count' => $page->posts()->count(),
            'listings_count' => $page->listings()->count(),
            'members_count' => $page->activeMembers()->count(),
            'team_count' => $page->activeMembers()->count(),
            'reports_count' => $reports->count(),
            'active_campaigns_count' => $page->campaigns()->whereIn('status', ['running', 'active'])->count(),
            'total_campaigns_spent' => (float) $page->campaigns()->sum('spent_amount'),
        ];

        return [
            'page' => (new PageResource($page))->resolve(),
            'owner' => $owner ? [
                'id' => $owner->id,
                'name' => $owner->name,
                'display_name' => $owner->display_name ?: $owner->name,
                'handle' => $owner->handle,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'avatar_url' => $owner->avatar_url,
                'badge' => $owner->badge,
                'status' => $owner->status,
                'created_at' => $owner->created_at?->toIso8601String(),
            ] : null,
            'members' => $members,
            'team' => $members,
            'posts' => $posts,
            'recent_posts' => $posts,
            'reports' => $reports,
            'certification' => $latestCert ? (new PageCertificationResource($latestCert))->resolve() : null,
            'certifications_history' => PageCertificationResource::collection($allCerts)->resolve(),
            'subscription' => $activeSub,
            'subscriptions_history' => $allSubs,
            'stats' => $stats,
        ];
    }
}
