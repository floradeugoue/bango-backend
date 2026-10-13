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
            'phone' => $this->phone,
            'handle' => $this->handle,
            'displayName' => $this->display_name,
            'avatarUrl' => $this->avatar_url,
            'bio' => $this->bio,
            'birthDate' => $this->birth_date?->format('Y-m-d'),
            'countryCode' => $this->country_code,
            'countryId' => $this->country_id,
            'city' => $this->city,
            'cityId' => $this->city_id,
            'neighbourhood' => $this->neighbourhood,
            'neighbourhoodId' => $this->neighbourhood_id,
            'locale' => $this->locale,
            'isVerified' => $this->is_verified,
            'accountType' => $this->account_type->value ?? 'personal',
            'gender' => $this->gender?->value,
            'genderHidden' => $this->gender_hidden,
            'onboardingProgress' => $this->onboarding_progress,
            'interests' => $this->whenLoaded('interests', function () {
                return $this->interests->pluck('id')->toArray();
            }),
        ];
    }
}
