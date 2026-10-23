<?php

namespace App\Services\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;
use App\Notifications\Page\PageSuspendedNotification;
use Illuminate\Http\UploadedFile;
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
}
