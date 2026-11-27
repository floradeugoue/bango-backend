<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'emailVerified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'emailVerifiedAt' => $this->email_verified_at?->toIso8601String(),
            'phone' => $this->phone,
            'handle' => $this->handle,
            'displayName' => $this->display_name ?: $this->name,
            'avatarUrl' => $this->avatar_url,
            'bio' => $this->bio,
            'website' => $this->website,
            'birthDate' => $this->birth_date?->format('Y-m-d'),
            'birthday_visibility' => $this->birthday_visibility ?? 'none',
            'birthdayVisibility' => $this->birthday_visibility ?? 'none',
            'countryCode' => $this->country_code,
            'countryId' => $this->country_id,
            'city' => $this->resource->cityName(),
            'cityId' => $this->city_id,
            'neighbourhood' => $this->resource->neighbourhoodName(),
            'neighbourhoodId' => $this->neighbourhood_id,
            'neighbourhood_verified' => $this->resource->hasVerifiedNeighbourhood(),
            'neighbourhoodVerified' => $this->resource->hasVerifiedNeighbourhood(),
            'neighbourhood_verified_at' => $this->neighbourhood_verified_at?->toIso8601String(),
            'neighbourhoodVerifiedAt' => $this->neighbourhood_verified_at?->toIso8601String(),
            'needs_neighbourhood_verification' => $this->resource->needsNeighbourhoodVerification(),
            'needsNeighbourhoodVerification' => $this->resource->needsNeighbourhoodVerification(),
            'locale' => $this->locale,
            'isVerified' => $this->is_verified,
            'certified' => (bool) ($this->is_certified ?? false),
            'is_certified' => (bool) ($this->is_certified ?? false),
            'certified_at' => $this->certified_at?->toIso8601String(),
            'accountType' => $this->account_type?->value ?? 'personal',
            'gender' => $this->gender?->value,
            'genderHidden' => $this->gender_hidden,
            'isPrivate' => (bool) ($this->is_private ?? false),
            'privacySettings' => $this->privacy_settings,
            'badge' => $this->badge ?? 'none',
            'verificationLapsed' => (bool) ($this->verification_lapsed ?? false),
            'uses_agenda' => (bool) ($this->uses_agenda ?? true),
            'usesAgenda' => (bool) ($this->uses_agenda ?? true),
            'restrictedUntil' => $this->restricted_until?->toIso8601String(),
            'accepted_terms_version' => $this->accepted_terms_version,
            'acceptedTermsVersion' => $this->accepted_terms_version,
            'terms_accepted_at' => $this->terms_accepted_at?->toIso8601String(),
            'termsAcceptedAt' => $this->terms_accepted_at?->toIso8601String(),
            'counts' => [
                'followers' => $this->followers()->count(),
                'following' => $this->following()->count(),
                'posts' => 0,
            ],
            'onboardingProgress' => $this->onboarding_progress,
            'onboarding_completed_at' => ($this->birth_date !== null && $this->terms_accepted_at !== null)
                ? ($this->onboarding_completed_at ?? $this->terms_accepted_at)?->toIso8601String()
                : null,
            'onboardingCompletedAt' => ($this->birth_date !== null && $this->terms_accepted_at !== null)
                ? ($this->onboarding_completed_at ?? $this->terms_accepted_at)?->toIso8601String()
                : null,
            'interests' => $this->whenLoaded('interests', function () {
                return $this->interests->pluck('id')->toArray();
            }),
        ];
    }
}
