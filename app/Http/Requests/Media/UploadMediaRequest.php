<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'mimes:jpeg,jpg,png,webp,mp4,mov',
                'max:102400', // 100 MB max
            ],
            'kind' => ['nullable', 'string', 'in:photo,video,square'],
        ];
    }

    /**
     * Validation contextuelle fine par type de média (image vs vidéo).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $file = $this->file('file');
            if (! $file || ! $file->isValid()) {
                return;
            }

            $mime = $file->getMimeType() ?? '';
            $isImage = str_starts_with($mime, 'image/') || in_array(strtolower($file->getClientOriginalExtension()), ['jpeg', 'jpg', 'png', 'webp'], true);

            if ($isImage && $file->getSize() > 15 * 1024 * 1024) {
                $validator->errors()->add('file', 'La taille de l\'image dépasse la limite maximale autorisée (15 MB).');
            }
        });
    }
}
