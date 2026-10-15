<?php

namespace App\Http\Requests\Story;

use Illuminate\Foundation\Http\FormRequest;

class StoreHighlightRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:20'], // Limite stricte de 20 caractères
            'story_ids' => ['required', 'array', 'min:1'],
            'story_ids.*' => ['required', 'string', 'uuid'],
            'cover_story_id' => ['nullable', 'string', 'uuid'],
            'cover_media_url' => ['nullable', 'string'],
        ];
    }
}
