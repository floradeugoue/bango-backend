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
            'handle' => $this->handle,
            'displayName' => $this->display_name,
            'avatarUrl' => $this->avatar_url,
            'countryCode' => $this->country_code,
            'city' => $this->city,
            'locale' => $this->locale,
            'isVerified' => $this->is_verified,
            'accountType' => $this->account_type->value ?? 'personal',
            'gender' => $this->gender?->value,
            'genderHidden' => $this->gender_hidden,
            'interests' => $this->whenLoaded('interests', function () {
                return $this->interests->pluck('id')->toArray();
            }),
        ];
    }
}
