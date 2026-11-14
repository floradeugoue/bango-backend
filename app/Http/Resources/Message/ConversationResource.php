<?php

namespace App\Http\Resources\Message;

use App\Enums\Delegation\DelegationStatus;
use App\Enums\Message\ConversationType;
use App\Models\Delegation\Delegation;
use App\Models\Message\Conversation;
use App\Models\Page\PageMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentUserId = $request->user()?->id;

        // Calcul du nombre de messages non lus pour l'utilisateur courant
        $unreadCount = 0;
        if ($currentUserId) {
            $member = $this->members->firstWhere('user_id', $currentUserId);
            if ($member && $member->last_read_at) {
                $unreadCount = $this->messages()
                    ->where('created_at', '>', $member->last_read_at)
                    ->where('sender_id', '!=', $currentUserId)
                    ->where(function ($q) {
                        $q->whereNull('metadata->undelivered')
                            ->orWhere('metadata->undelivered', false);
                    })
                    ->count();
            } elseif ($member) {
                $unreadCount = $this->messages()
                    ->where('sender_id', '!=', $currentUserId)
                    ->where(function ($q) {
                        $q->whereNull('metadata->undelivered')
                            ->orWhere('metadata->undelivered', false);
                    })
                    ->count();
            }
        }

        // Titre par défaut : pour les conversations directes sans titre explicite, utiliser le nom de l'interlocuteur
        $title = $this->title;
        $avatarUrl = $this->avatar_url;
        $isMuted = false;
        $isArchived = false;
        $pinnedAt = null;
        $page = $this->page_id
            ? ($this->relationLoaded('page') ? $this->page : $this->page()->first())
            : null;
        $activePage = $request->attributes->get('active_page') ?? $request->attributes->get('acting_page');
        $isPageClientViewer = $page
            && $currentUserId
            && (int) $this->created_by_id === (int) $currentUserId
            && (! $activePage || (int) $activePage->id !== (int) $page->id);

        if ($currentUserId) {
            $currentMember = $this->members->firstWhere('user_id', $currentUserId);
            $isMuted = (bool) ($currentMember?->is_muted);
            $pinnedAt = $currentMember?->pinned_at?->toIso8601String();
            $isArchived = in_array($currentUserId, $this->metadata['archived_by'] ?? [], true);

            if ($page) {
                if ($isPageClientViewer) {
                    $title = $page->name ?: $title;
                    $avatarUrl = $page->logo_url ?: $avatarUrl;
                } else {
                    $creator = $this->relationLoaded('creator') ? $this->creator : $this->creator()->first();
                    if ($creator) {
                        $title = $creator->display_name ?: ($creator->name ?: ($creator->handle ?: $title));
                        $avatarUrl = $creator->avatar_url ?: $avatarUrl;
                    }
                }
            } elseif (! $title && $this->type === ConversationType::Direct) {
                $otherMember = $this->members->firstWhere('user_id', '!=', $currentUserId);
                $otherUser = $otherMember?->user;
                if ($otherUser) {
                    $title = $otherUser->display_name ?: ($otherUser->name ?: ($otherUser->handle ?: 'Utilisateur'));
                }
            }
        }

        $visibleMembers = $this->whenLoaded('members', function () use ($isPageClientViewer, $currentUserId) {
            $members = $this->members;
            if ($isPageClientViewer && $currentUserId) {
                $members = $members->where('user_id', $currentUserId)->values();
            }

            return ConversationMemberResource::collection($members);
        });

        return [
            'id' => (string) $this->id,
            'thread_id' => (string) $this->id,
            'type' => $this->type->value,
            'page_id' => $this->page_id ? (int) $this->page_id : null,
            'page' => $page ? [
                'id' => (int) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'avatar_url' => $page->logo_url,
                'is_certified' => $page->isCertified(),
            ] : null,
            'title' => $title,
            'avatar_url' => $avatarUrl,
            'is_muted' => $isMuted,
            'is_archived' => $isArchived,
            'pinned_at' => $pinnedAt,
            'is_pinned' => $pinnedAt !== null,
            'listing_id' => $this->listing_id ? (string) $this->listing_id : null,
            'listing' => $this->whenLoaded('listing', function () {
                if (! $this->listing) {
                    return null;
                }

                return [
                    'id' => (string) $this->listing->id,
                    'title' => $this->listing->title,
                    'price' => (float) $this->listing->price,
                    'currency' => $this->listing->currency,
                    'status' => $this->listing->status->value,
                    'mode' => $this->listing->mode->value,
                    'kind' => $this->listing->kind->value,
                    'cover_image' => $this->listing->cover_image_url ?? $this->listing->media?->first()?->url,
                    'owner_id' => $this->listing->owner_id,
                    'agent_id' => $this->listing->agent_id,
                ];
            }),
            'members' => $visibleMembers,
            'latest_message' => (function () use ($currentUserId) {
                if (! $this->relationLoaded('latestMessage') || ! $this->latestMessage) {
                    return null;
                }
                $latest = $this->latestMessage;
                $isUndelivered = (bool) ($latest->metadata['undelivered'] ?? false);
                if ($isUndelivered && $currentUserId && $latest->sender_id !== $currentUserId) {
                    // Trouver le dernier message visible pour cet utilisateur
                    $fallback = $this->messages()
                        ->visibleTo($currentUserId)
                        ->with(['sender:id,name,display_name,handle,avatar_url,is_verified,is_certified'])
                        ->orderByDesc('created_at')
                        ->first();

                    return $fallback ? new MessageResource($fallback) : null;
                }

                return new MessageResource($latest);
            })(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'status' => $this->status ?? 'active',
            'is_request' => $this->status === 'pending',
            'admins_only' => (bool) $this->admins_only,
            'is_read_only' => (bool) $this->is_read_only,
            'on_behalf_of' => (function () {
                if (! $this->listing_id) {
                    return null;
                }
                $listing = $this->listing;
                if (! $listing) {
                    return null;
                }
                $activeDelegation = Delegation::where('listing_id', $listing->id)
                    ->where('status', DelegationStatus::Active->value)
                    ->whereNull('revoked_at')
                    ->with('agent')
                    ->first();

                if (! $activeDelegation || ! $activeDelegation->agent) {
                    return null;
                }

                $agent = $activeDelegation->agent;

                return [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->display_name ?: $agent->name,
                    'agent_handle' => $agent->handle,
                    'agent_avatar_url' => $agent->avatar_url,
                    'landlord_id' => $listing->owner_id,
                    'label' => "Au nom du bailleur ({$agent->name})",
                ];
            })(),
            'assigned_to_id' => $this->assigned_to_id ? (int) $this->assigned_to_id : null,
            'assigned_to' => (function () {
                $assignedUser = $this->relationLoaded('assignedTo')
                    ? $this->assignedTo
                    : ($this->assigned_to_id ? User::find($this->assigned_to_id) : null);

                if (! $assignedUser) {
                    return null;
                }

                $pageMember = $this->page_id
                    ? PageMember::where('page_id', $this->page_id)->where('user_id', $assignedUser->id)->first()
                    : null;

                return [
                    'id' => (int) $assignedUser->id,
                    'user_id' => (int) $assignedUser->id,
                    'member_id' => $pageMember ? (int) $pageMember->id : (int) $assignedUser->id,
                    'name' => $assignedUser->display_name ?: $assignedUser->name,
                    'handle' => $assignedUser->handle,
                    'avatar_url' => $assignedUser->avatar_url,
                ];
            })(),
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
