<?php

namespace App\Http\Requests\Listing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveListingDraftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'submission_key' => ['nullable', 'string', 'max:128'],
            'key' => ['nullable', 'string', 'max:128'],
            'step' => ['nullable', 'in:video,photos,features,price,place,preview'],
            'values' => ['nullable', 'array'],
            'title' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:4000'],
            'mode' => ['nullable', 'in:rent,sale,flatshare'],
            'kind' => ['nullable', 'in:studio,flat,villa,room,office,land'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'deposit_months' => ['nullable', 'integer', 'min:0', 'max:36'],
            'advance_months' => ['nullable', 'integer', 'min:0', 'max:36'],
            'visit_fee' => ['nullable', 'numeric', 'min:0'],
            'charges' => ['nullable', 'numeric', 'min:0'],
            'negotiable' => ['nullable', 'boolean'],
            'utilities_included' => ['nullable', 'boolean'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'showers' => ['nullable', 'integer', 'min:0', 'max:100'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'city' => ['nullable', 'string', 'max:100'],
            'neighbourhood' => ['nullable', 'string', 'max:100'],
            'landmark' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:80'],
            'pin' => ['nullable', 'array'],
            'pin.lat' => ['required_with:pin', 'numeric', 'between:-85,85'],
            'pin.lng' => ['required_with:pin', 'numeric', 'between:-180,180'],
            'amenities' => ['nullable', 'array'],
            'photos' => ['nullable', 'array', 'max:20'],
            'photo_rooms' => ['nullable', 'array'],
        ];
    }
}
