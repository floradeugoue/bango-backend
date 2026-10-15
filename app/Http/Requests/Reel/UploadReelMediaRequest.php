<?php

namespace App\Http\Requests\Reel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadReelMediaRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:102400', // 100 Mo max
                'mimetypes:video/mp4,video/quicktime,video/webm,video/3gpp,video/x-m4v,image/jpeg,image/png,image/webp,audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/aac,audio/ogg,audio/x-m4a,audio/mp4',
            ],
            'kind' => ['nullable', 'string', 'in:video,cover,audio,clip,draft'],
            'is_draft' => ['nullable', 'boolean'],
        ];
    }
}
