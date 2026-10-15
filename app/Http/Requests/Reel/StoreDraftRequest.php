<?php

namespace App\Http\Requests\Reel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDraftRequest extends FormRequest
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
            'id' => ['nullable', 'string'],
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
            'composition.clips' => ['nullable', 'array'],
            'composition.maxMs' => ['nullable', 'integer'],
            'composition.soundId' => ['nullable', 'string'],
            'composition.soundStart' => ['nullable', 'integer'],
            'composition.originalVolume' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'composition.musicVolume' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'composition.voiceVolume' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'composition.filter' => ['nullable', 'string'],
            'composition.filterIntensity' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'composition.overlays' => ['nullable', 'array'],
            'composition.coverAt' => ['nullable', 'numeric'],
            'composition.coverUri' => ['nullable', 'string'],
            'listing_id' => ['nullable', 'string'],
        ];
    }
}
