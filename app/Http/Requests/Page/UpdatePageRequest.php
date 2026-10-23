<?php

namespace App\Http\Requests\Page;

use App\Enums\Page\PageSector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalise l'identifiant en forme slug avant la règle d'unicité.
     */
    protected function prepareForValidation(): void
    {
        $handle = $this->input('handle');

        if (is_string($handle) && ($slug = Str::slug($handle)) !== '') {
            $this->merge(['handle' => $slug]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'handle.unique' => 'handle taken',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $pageId = $this->route('page') ? (is_object($this->route('page')) ? $this->route('page')->id : $this->route('page')) : null;

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'handle' => ['sometimes', 'string', 'min:2', 'max:64', 'alpha_dash', Rule::unique('pages', 'handle')->ignore($pageId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'sector' => ['sometimes', 'string', Rule::in(PageSector::values())],
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
