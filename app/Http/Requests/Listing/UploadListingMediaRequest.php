<?php

namespace App\Http\Requests\Listing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadListingMediaRequest extends FormRequest
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
            'file' => ['required', 'file'],
            'type' => ['required', 'in:image,video'],
            'room' => ['nullable', 'in:living,bedroom,kitchen,shower,outside'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:600'],
            'width' => ['nullable', 'integer'],
            'height' => ['nullable', 'integer'],
        ];
    }
}
