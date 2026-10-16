<?php

namespace App\Http\Requests\Page;

use App\Enums\Page\PageSector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PageSearchRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'query' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', Rule::in(PageSector::values())],
            'city' => ['nullable', 'string', 'max:100'],
            'neighbourhood' => ['nullable', 'string', 'max:100'],
            'certified' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
