<?php

namespace App\Http\Requests\Listing;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadListingMediaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:256000'],
            'type' => ['required', 'in:image,video'],
            'room' => ['nullable', 'in:living,bedroom,kitchen,shower,outside'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:600'],
            'width' => ['nullable', 'integer'],
            'height' => ['nullable', 'integer'],
            'video_trim' => ['nullable', 'array'],
            'video_trim.start_ms' => ['required_with:video_trim', 'integer', 'min:0'],
            'video_trim.end_ms' => ['required_with:video_trim', 'integer', 'gt:video_trim.start_ms'],
            'video_cover_ms' => ['nullable', 'integer', 'min:0'],
            'video_chapters' => ['nullable', 'array'],
            'video_chapters.*.room' => ['required', 'string', 'in:entry,living,kitchen,shower,outside'],
            'video_chapters.*.at_ms' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Le fichier média est requis.',
            'file.file' => 'Le fichier envoyé est invalide ou dépasse la taille maximale autorisée.',
            'file.max' => 'Le fichier dépasse la taille maximale autorisée (250 Mo).',
            'type.required' => 'Le type de média (image ou video) est requis.',
            'type.in' => 'Le type de média doit être image ou video.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $trim = $this->input('video_trim');
            if (is_array($trim) && isset($trim['start_ms'], $trim['end_ms'])) {
                $durationMs = (int) $trim['end_ms'] - (int) $trim['start_ms'];
                if ($durationMs < 30000) {
                    $validator->errors()->add(
                        'video_trim',
                        'La vidéo découpée doit durer au minimum 30 secondes.'
                    );
                }
            }
        });
    }
}
