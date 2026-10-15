<?php

namespace App\Http\Requests\Reel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PublishReelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'draft_id' => ['nullable', 'string'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'topic' => ['nullable', 'string', 'max:50'],
            'sound_id' => ['nullable', 'string', 'max:100'],
            'video_url' => ['nullable', 'string'],
            'thumbnail_url' => ['nullable', 'string'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'allow_download' => ['nullable', 'boolean'],
            'comments_closed' => ['nullable', 'boolean'],
            'subtitles' => ['nullable', 'string'],
            'subtitles_locale' => ['nullable', 'string', 'max:10'],
            'composition' => ['nullable', 'array'],
            'listing_id' => ['nullable', 'string'],
        ];
    }
}
