<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        $badges = [];
        $badge = $this->badge ?? 'none';
        if ($badge === 'landlord') {
            $badges = ['Bailleur'];
        } elseif ($badge === 'agent') {
            $badges = ['Agent'];
        } elseif ($badge === 'double') {
            $badges = ['Bailleur', 'Agent'];
        } elseif ($badge === 'page') {
            $badges = ['Page'];
        }

        $tones = ['warm', 'sand', 'deep'];
        $hash = crc32((string) $this->id);
        $tone = $tones[abs($hash) % count($tones)];

        $isFollowing = $currentUser ? $currentUser->isFollowing($this->resource) : false;
        $isFollowedBy = $currentUser ? $this->isFollowing($currentUser) : false;
        $hasRequested = $currentUser ? $currentUser->hasRequestedToFollow($this->resource) : false;
        $isBlocked = $currentUser ? $currentUser->hasBlocked($this->resource) : false;
        $isBlockedBy = $currentUser ? $currentUser->isBlockedBy($this->resource) : false;
        $canViewContent = $currentUser ? $currentUser->canViewContentOf($this->resource) : ! $this->is_private;

        $isMutual = $isFollowing && $isFollowedBy;
        $isOwner = $currentUser && (int) $currentUser->id === (int) $this->id;
        $canViewBirthday = $isOwner || ($this->birthday_visibility === 'mutuals' && $isMutual && ! $isBlocked && ! $isBlockedBy);
        $todayInTargetTimezone = now()->setTimezone($this->resource->resolveTimezone());
        $isBirthdayToday = $canViewBirthday && $this->resource->isBirthdayOnDate($todayInTargetTimezone);
        $birthdayDayMonth = ($canViewBirthday && $this->birth_date) ? $this->birth_date->format('d/m') : null;

        $showNeighbourhood = (bool) data_get($this->privacy_settings, 'showNeighbourhood', true);
        $rawCity = $this->resource->cityName();
        $rawNeighbourhood = $this->resource->neighbourhoodName();
        $neighbourhood = $showNeighbourhood ? $rawNeighbourhood : null;
        $neighbourhoodVerified = $showNeighbourhood && $this->resource->hasVerifiedNeighbourhood();
        $place = collect([$neighbourhood, $rawCity])->filter()->join(', ');

        return [
            'id' => $this->id,
            'name' => $this->display_name ?: $this->name,
            'handle' => $this->handle,
            'avatarUrl' => $this->avatar_url,
            'place' => $place,
            'countryCode' => $this->country_code,
            'city' => $rawCity,
            'neighbourhood' => $neighbourhood,
            'neighbourhood_verified' => $neighbourhoodVerified,
            'neighbourhoodVerified' => $neighbourhoodVerified,
            'bio' => $this->bio ?? '',
            'website' => $this->website,
            'badge' => $badge,
            'badges' => $badges,
            'verified' => (bool) ($this->badge && $this->badge !== 'none' && ! $this->verification_lapsed),
            'certified' => (bool) ($this->is_certified ?? false),
            'is_certified' => (bool) ($this->is_certified ?? false),
            'verificationLapsed' => (bool) ($this->verification_lapsed ?? false),
            'private' => (bool) ($this->is_private ?? false),
            'tone' => $tone,
            'counts' => [
                'followers' => $this->followers()->count(),
                'following' => $this->following()->count(),
                'posts' => $this->publishedPostsAndReelsCount(),
            ],
            'isFollowing' => $isFollowing,
            'isFollowedBy' => $isFollowedBy,
            'hasRequested' => $hasRequested,
            'isBlocked' => $isBlocked,
            'isBlockedBy' => $isBlockedBy,
            'canViewContent' => $canViewContent,
            'can_comment' => $this->resource->canBeCommentedBy($currentUser),
            'can_message' => $this->resource->canBeMessagedBy($currentUser),
            'can_tag' => $this->resource->canBeTaggedBy($currentUser),
            'canComment' => $this->resource->canBeCommentedBy($currentUser),
            'canMessage' => $this->resource->canBeMessagedBy($currentUser),
            'canTag' => $this->resource->canBeTaggedBy($currentUser),
            'is_birthday_today' => $isBirthdayToday,
            'isBirthdayToday' => $isBirthdayToday,
            'birthday' => $birthdayDayMonth,
        ];
    }
}
