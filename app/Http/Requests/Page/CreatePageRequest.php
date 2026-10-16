<?php

namespace App\Http\Requests\Page;

use App\Enums\Page\PageSector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'handle' => ['nullable', 'string', 'min:2', 'max:64', 'alpha_dash', 'unique:pages,handle'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sector' => ['required', 'string', Rule::in(PageSector::values())],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
            'cover' => ['nullable', 'file', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
            'logo_url' => ['nullable', 'string', 'url', 'max:500'],
            'cover_url' => ['nullable', 'string', 'url', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'neighbourhood' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
