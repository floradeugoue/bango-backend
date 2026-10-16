<?php

namespace App\Http\Requests\Boost;

use Illuminate\Foundation\Http\FormRequest;

class EstimateCampaignRequest extends FormRequest
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
            'budget' => ['required', 'numeric', 'min:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'boostable_type' => ['nullable', 'string', 'in:post,posts,reel,reels,listing,listings'],
            'boostable_id' => ['nullable', 'string'],
            'targeting' => ['nullable', 'array'],
            'targeting.city' => ['nullable', 'string', 'max:100'],
            'targeting.neighbourhoods' => ['nullable', 'array'],
            'targeting.neighbourhoods.*' => ['string', 'max:100'],
            'targeting.radius_km' => ['nullable', 'integer', 'min:1', 'max:100'],
            'targeting.age_min' => ['nullable', 'integer', 'min:13', 'max:100'],
            'targeting.age_max' => ['nullable', 'integer', 'min:13', 'max:100', 'gte:targeting.age_min'],
            'targeting.interests' => ['nullable', 'array'],
            'targeting.interests.*' => ['string', 'max:50'],
        ];
    }
}
