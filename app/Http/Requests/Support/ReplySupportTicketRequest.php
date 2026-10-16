<?php

namespace App\Http\Requests\Support;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReplySupportTicketRequest extends FormRequest
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
            'message' => ['required', 'string', 'min:1', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:jpeg,jpg,png,webp,pdf,txt,log', 'max:10240'],
            'is_internal' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Le message ne peut pas être vide.',
            'message.max' => 'Le message ne peut pas dépasser 5000 caractères.',
            'attachments.max' => 'Vous ne pouvez pas joindre plus de 5 fichiers.',
            'attachments.*.mimes' => 'Format de pièce jointe non autorisé.',
            'attachments.*.max' => 'La taille maximale par pièce jointe est de 10 Mo.',
        ];
    }
}
