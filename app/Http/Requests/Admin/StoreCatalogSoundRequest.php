<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreCatalogSoundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['required', 'string', 'max:255'],
            'genre' => ['required', 'string', 'max:100'],
            'file' => ['required_without:audio_file', 'nullable', 'file', 'mimes:mp3,wav,aac,ogg,m4a', 'max:30720'],
            'audio_file' => ['required_without:file', 'nullable', 'file', 'mimes:mp3,wav,aac,ogg,m4a', 'max:30720'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            'provenance' => ['nullable', 'string', 'max:255'],
            'license_type' => ['nullable', 'string', 'max:100'],
            'license_name' => ['nullable', 'string', 'max:255'],
            'license_url' => ['nullable', 'url', 'max:1000'],
            'license_terms' => ['nullable', 'string', 'max:5000'],
            'usage_restrictions' => ['nullable', 'string', 'max:5000'],
            'rights_status' => ['nullable', 'string', 'in:pending,verified,rejected'],
            'is_published' => ['nullable', 'boolean'],
            'base_uses' => ['nullable', 'integer', 'min:0'],
            'territories' => ['nullable', 'array'],
        ];
    }

    /**
     * Obtenir le fichier audio téléversé quel que soit le nom du champ (file ou audio_file).
     */
    public function getAudioFile(): ?UploadedFile
    {
        return $this->file('file') ?? $this->file('audio_file');
    }
}
