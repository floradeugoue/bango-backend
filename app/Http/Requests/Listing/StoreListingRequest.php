<?php

namespace App\Http\Requests\Listing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreListingRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:70'],
            'description' => ['nullable', 'string', 'max:4000'],
            'mode' => ['required', 'in:rent,sale,flatshare'],
            'kind' => ['required', 'in:studio,flat,villa,room,office,land'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'deposit_months' => ['nullable', 'integer', 'min:0', 'max:36'],
            'advance_months' => ['nullable', 'integer', 'min:0', 'max:36'],
            'visit_fee' => ['nullable', 'numeric', 'min:0'],
            'charges' => ['nullable', 'numeric', 'min:0'],
            'negotiable' => ['nullable', 'boolean'],
            'utilities_included' => ['nullable', 'boolean'],
            'uses_agenda' => ['nullable', 'boolean'],
            'bedrooms' => ['required', 'integer', 'min:0', 'max:100'],
            'showers' => ['required', 'integer', 'min:0', 'max:100'],
            'area' => ['required', 'numeric', 'min:1'],
            'floor' => ['nullable', 'integer', 'min:0', 'max:200'],
            'available_from' => ['nullable', 'date'],
            'min_lease_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'city' => ['required', 'string', 'max:100'],
            'neighbourhood' => ['required', 'string', 'max:100'],
            'landmark' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:160'],
            'pin' => ['nullable', 'array'],
            'pin.lat' => ['required_with:pin', 'numeric', 'between:-85,85'],
            'pin.lng' => ['required_with:pin', 'numeric', 'between:-180,180'],
            'lat' => ['nullable', 'numeric', 'between:-85,85'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'amenities' => ['nullable', 'array'],
            'submission_key' => ['nullable', 'string', 'max:128'],
            'key' => ['nullable', 'string', 'max:128'],
            'photos' => ['nullable', 'array', 'max:20'],
            'photo_rooms' => ['nullable', 'array'],
            'photo_rooms.*' => ['nullable', 'in:living,bedroom,kitchen,shower,outside'],
            'video' => ['nullable', 'string'],
            'video_trim' => ['nullable', 'array'],
            'video_trim.start_ms' => ['required_with:video_trim', 'integer', 'min:0'],
            'video_trim.end_ms' => ['required_with:video_trim', 'integer', 'gt:video_trim.start_ms'],
            'video_cover_ms' => ['nullable', 'integer', 'min:0'],
            'video_chapters' => ['nullable', 'array'],
            'video_chapters.*.room' => ['required', 'string', 'in:entry,living,kitchen,shower,outside'],
            'video_chapters.*.at_ms' => ['required', 'integer', 'min:0'],
            'status' => ['nullable', 'string', 'in:draft,review,published'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $trim = $this->input('video_trim');
            if (is_array($trim) && isset($trim['start_ms'], $trim['end_ms'])) {
                $durationMs = (int) $trim['end_ms'] - (int) $trim['start_ms'];
                if ($durationMs < 30000) {
                    $validator->errors()->add(
                        'video_trim',
                        'La vidéo découpée doit durer au minimum 30 secondes.'
                    );
                }
            }
        });
    }
}
