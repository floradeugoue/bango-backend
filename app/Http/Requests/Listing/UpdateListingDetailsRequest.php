<?php

namespace App\Http\Requests\Listing;

use App\Models\Listing\Listing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

class UpdateListingDetailsRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:70'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:80'],
            'address' => ['sometimes', 'nullable', 'string', 'max:160'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'deposit_months' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'advance_months' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'visit_fee' => ['sometimes', 'numeric', 'min:0'],
            'charges' => ['sometimes', 'numeric', 'min:0'],
            'negotiable' => ['sometimes', 'boolean'],
            'utilities_included' => ['sometimes', 'boolean'],
            'uses_agenda' => ['sometimes', 'nullable', 'boolean'],
            'amenities' => ['sometimes', 'nullable', 'array'],
            'amenities.*' => ['string', 'max:50'],
            'video_trim' => ['sometimes', 'nullable', 'array'],
            'video_trim.start_ms' => ['required_with:video_trim', 'integer', 'min:0'],
            'video_trim.end_ms' => ['required_with:video_trim', 'integer', 'gt:video_trim.start_ms'],
            'video_cover_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'video_chapters' => ['sometimes', 'nullable', 'array'],
            'video_chapters.*.room' => ['required', 'string', 'in:entry,living,kitchen,shower,outside'],
            'video_chapters.*.at_ms' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $listingId = $this->route('id') ?? $this->route('listing');
            $priceFields = ['price', 'deposit_months', 'advance_months', 'visit_fee', 'charges', 'negotiable'];
            $hasPriceField = false;

            foreach ($priceFields as $field) {
                if ($this->has($field)) {
                    $hasPriceField = true;
                    break;
                }
            }

            if ($hasPriceField && $listingId) {
                $listing = Listing::find($listingId);
                $user = $this->user();
                if ($listing && $user && $user->id !== $listing->owner_id && ! $user->isAdmin()) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Seul le propriétaire du bien peut modifier le prix ou les conditions financières (CGU article 12).',
                        'kind' => 'price-owner-only',
                        'error' => 'PRICE_OWNER_ONLY',
                    ], 403));
                }
            }

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
