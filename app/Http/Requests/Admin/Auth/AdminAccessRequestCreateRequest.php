<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AdminAccessRequestCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:191', 'ends_with:@bango.app'],
            'department' => ['required', 'string', 'max:100'],
            'requested_role' => ['required', 'string', 'in:direction,moderation,support,finance,verification'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.ends_with' => 'L\'adresse email doit impérativement être une adresse professionnelle @bango.app.',
            'requested_role.in' => 'Le rôle demandé n\'est pas un rôle interne autorisé.',
            'reason.min' => 'Veuillez motiver votre demande d\'accès plus en détail (au moins 10 caractères).',
        ];
    }
}
