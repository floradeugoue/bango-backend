<?php

namespace App\Http\Requests\Boost;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'boostable_type' => ['required', 'string', 'in:post,posts,reel,reels,listing,listings'],
            'boostable_id' => ['required', 'string'],
            'objective' => ['nullable', 'string', 'in:reach,profile_visits,followers,messages,engagement'],
            'budget' => ['required', 'numeric', 'min:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:30'],
            'targeting' => ['nullable', 'array'],
            'targeting.city' => ['nullable', 'string', 'max:100'],
            'targeting.neighbourhoods' => ['nullable', 'array'],
            'targeting.neighbourhoods.*' => ['string', 'max:100'],
            'targeting.radius_km' => ['nullable', 'integer', 'min:1', 'max:100'],
            'targeting.age_min' => ['nullable', 'integer', 'min:13', 'max:100'],
            'targeting.age_max' => ['nullable', 'integer', 'min:13', 'max:100', 'gte:targeting.age_min'],
            'targeting.interests' => ['nullable', 'array'],
            'targeting.interests.*' => ['string', 'max:50'],
            'payment_method' => ['nullable', 'string', 'in:credit,external'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
