<?php

namespace App\Http\Requests\Message;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
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
            'body' => [
                'nullable',
                'string',
                'max:5000',
                'required_without:attachment_path',
            ],
            'type' => [
                'nullable',
                'string',
                'in:text,voice,image,listing,story_reply,system',
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
            'attachment_path' => [
                'nullable',
                'string',
                'max:1024',
            ],
            'attachment_mime' => [
                'nullable',
                'string',
                'max:64',
            ],
            'attachment_size' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'attachment_duration_ms' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'Le contenu du message ou une pièce jointe est requis.',
            'body.max' => 'Le message ne peut pas dépasser 5 000 caractères.',
            'type.in' => 'Le type de message sélectionné n\'est pas valide.',
        ];
    }
}
