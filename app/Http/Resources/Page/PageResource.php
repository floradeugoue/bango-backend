<?php

namespace App\Http\Resources\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageRole;
use App\Models\Page\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Page
 */
class PageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isMember = $viewer ? $this->hasMember($viewer) : false;
        $memberRole = $viewer ? $this->memberRole($viewer) : null;
        $isOwner = $viewer ? $this->isOwner($viewer) : false;
        $isAdmin = $viewer ? $viewer->isAdmin() : false;

        $certificationStatusValue = $this->certification_status instanceof PageCertificationStatus
            ? $this->certification_status->value
            : $this->certification_status;

        $data = [
            'id' => (int) $this->id,
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description,
            'sector' => $this->sector->value,
            'sector_label' => $this->sector->label(),
            'logo_url' => $this->logo_url,
            'cover_url' => $this->cover_url,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'address' => $this->address,
            'city' => $this->city,
            'neighbourhood' => $this->neighbourhood,
            'certification_status' => $certificationStatusValue,
            'is_certified' => $this->isCertified(),
            'badge' => $this->isCertified() ? 'page_certified' : 'none',
            'status' => $this->status->value,
            'is_hidden' => (bool) $this->is_hidden,
            'followers_count' => (int) ($this->followers_count ?? $this->followers()->count()),
            'is_following' => $viewer ? $this->isFollowedBy($viewer) : false,
            'inbox_assigned_to' => $this->inbox_assigned_to_id ? [
                'id' => (int) $this->inbox_assigned_to_id,
                'name' => $this->inboxAssignee?->name,
                'handle' => $this->inboxAssignee?->handle,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        // Informations contextuelles d'affiliation pour l'utilisateur connecté
        if ($viewer) {
            $data['is_member'] = $isMember;
            $data['is_owner'] = $isOwner;
            $data['current_user_role'] = $memberRole?->value;
            $data['permissions'] = [
                'can_edit' => ($isOwner || $isAdmin) && $this->isActive(),
                'can_publish' => ($memberRole && in_array($memberRole, [PageRole::Owner, PageRole::Editor], true)) && $this->isActive(),
                'can_manage_members' => ($isOwner || $isAdmin) && $this->isActive(),
                'can_manage_finances' => ($isOwner || $isAdmin) && $this->isActive(),
                'can_access_inbox' => $isMember || $isAdmin,
                'can_submit_certification' => ($isOwner || $isAdmin) && $this->isActive(),
            ];
        }

        // Relations chargées
        if ($this->relationLoaded('owner')) {
            $data['owner'] = [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'handle' => $this->owner->handle,
            ];
        }

        if ($this->relationLoaded('activeMembers') && ($isMember || $isAdmin)) {
            $data['members_count'] = $this->activeMembers->count();
        }

        return $data;
    }
}
