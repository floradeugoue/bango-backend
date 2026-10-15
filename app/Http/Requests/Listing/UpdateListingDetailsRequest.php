<?php

namespace App\Http\Requests\Listing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            'price' => ['sometimes', 'numeric', 'min:0'],
            'deposit_months' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'advance_months' => ['sometimes', 'integer', 'min:0', 'max:36'],
            'visit_fee' => ['sometimes', 'numeric', 'min:0'],
            'charges' => ['sometimes', 'numeric', 'min:0'],
            'negotiable' => ['sometimes', 'boolean'],
            'utilities_included' => ['sometimes', 'boolean'],
        ];
    }
}
