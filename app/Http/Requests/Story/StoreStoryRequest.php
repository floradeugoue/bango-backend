<?php

namespace App\Http\Requests\Story;

use App\Enums\Story\StoryAudience;
use App\Enums\Story\StoryStickerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoryRequest extends FormRequest
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
            'audience' => ['sometimes', 'string', Rule::in(StoryAudience::values())],
            'allow_replies' => ['sometimes', 'boolean'],
            'allow_archive' => ['sometimes', 'boolean'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'filter' => ['nullable', 'string', 'max:64'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:30'],
            'sound_id' => ['nullable', 'string', 'exists:sounds,id'],
            'sound_start_at' => ['nullable', 'integer', 'min:0'],

            'file' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp,mp4,mov,webm,3gp,m4v', 'max:51200'],
            'media' => ['nullable', 'file', 'mimes:jpeg,jpg,png,webp,mp4,mov,webm,3gp,m4v', 'max:51200'],

            'stickers' => ['nullable', 'array'],
            'stickers.*.type' => ['required_with:stickers', 'string', Rule::in(StoryStickerType::values())],
            'stickers.*.x' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'stickers.*.y' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'stickers.*.scale' => ['nullable', 'numeric'],
            'stickers.*.rotation' => ['nullable', 'numeric'],
            'stickers.*.data' => ['nullable', 'array'],

            'slides' => ['nullable', 'array'],
            'slides.*.caption' => ['nullable', 'string', 'max:1000'],
            'slides.*.filter' => ['nullable', 'string', 'max:64'],
            'slides.*.duration' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}
