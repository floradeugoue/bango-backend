<?php

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

class UploadEvidenceRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,mp4,pdf', 'max:10240'],
            'report_ref' => ['nullable', 'string'],
        ];
    }
}
