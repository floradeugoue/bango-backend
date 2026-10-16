<?php

namespace App\Http\Requests\Message;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVoiceMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'voice' => [
                'required',
                'file',
                'max:25600', // 25 MB max
                'mimes:mp3,wav,ogg,aac,m4a,mp4',
            ],
            'duration_ms' => [
                'nullable',
                'integer',
                'min:100',
                'max:3600000', // Max 1 heure
            ],
            'client_message_id' => [
                'nullable',
                'string',
                'max:128',
            ],
            'reply_to_message_id' => [
                'nullable',
                'uuid',
                'exists:messages,id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'voice.required' => 'Le fichier audio de la note vocale est obligatoire.',
            'voice.file' => 'Le fichier téléversé doit être un fichier valide.',
            'voice.max' => 'La note vocale ne doit pas dépasser 25 Mo.',
            'voice.mimes' => 'Format audio non supporté. Formats acceptés : mp3, wav, ogg, aac, m4a.',
        ];
    }
}
