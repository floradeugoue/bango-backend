<?php

namespace App\Http\Requests\Translation;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TranslateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'text' => ['required_without_all:type,id', 'nullable', 'string', 'max:5000'],
            'type' => ['required_without:text', 'nullable', 'string', 'in:post,reel,comment,reel_comment'],
            'id' => ['required_without:text', 'nullable'],
            'target' => ['required', 'string', 'in:fr,en'],
        ];
    }

    /**
     * Custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required_without_all' => 'Le texte à traduire ou un élément (type et id) est requis.',
            'type.required_without' => 'Le type d\'élément à traduire est requis.',
            'type.in' => 'Le type doit être post, reel, comment ou reel_comment.',
            'id.required_without' => 'L\'identifiant de l\'élément est requis.',
            'target.required' => 'La langue cible est requise.',
            'target.in' => 'La langue cible doit être fr ou en.',
        ];
    }
}
