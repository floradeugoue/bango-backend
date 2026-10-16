<?php

namespace App\Http\Requests\Support;

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->isSuspended();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:3', 'max:255'],
            'category' => ['required', 'string', Rule::enum(TicketCategory::class)],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
            'priority' => ['nullable', 'string', Rule::enum(TicketPriority::class)],
            'incident_id' => ['nullable', 'string', 'max:64'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:jpeg,jpg,png,webp,pdf,txt,log', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => 'Le sujet du ticket est obligatoire.',
            'category.required' => 'La catégorie est obligatoire.',
            'category.Illuminate\Validation\Rules\Enum' => 'La catégorie sélectionnée est invalide.',
            'message.required' => 'La description de votre problème est obligatoire.',
            'message.min' => 'Le message doit contenir au moins 5 caractères.',
            'attachments.max' => 'Vous ne pouvez pas joindre plus de 5 fichiers.',
            'attachments.*.mimes' => 'Le format de pièce jointe n\'est pas autorisé (formats acceptés : jpeg, jpg, png, webp, pdf, txt, log).',
            'attachments.*.max' => 'La taille maximale par pièce jointe est de 10 Mo.',
        ];
    }
}
