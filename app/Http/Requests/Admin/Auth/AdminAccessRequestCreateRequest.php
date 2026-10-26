<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminAccessRequestCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        $allowedDomains = $this->allowedDomains();

        $emailRules = ['required', 'string', 'email', 'max:191'];

        if (! empty($allowedDomains)) {
            $emailRules[] = 'ends_with:'.implode(',', $allowedDomains);
        }

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => $emailRules,
            'department' => ['required', 'string', 'max:100'],
            'requested_role' => ['required', 'string', 'in:direction,moderation,support,finance,verification'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * Récupère la liste des suffixes/domaines autorisés depuis la configuration.
     *
     * @return array<string>
     */
    public function allowedDomains(): array
    {
        $raw = config('auth.admin_allowed_email_domains');

        if (empty($raw)) {
            return [];
        }

        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        $normalized = [];
        foreach ($items as $item) {
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                // S'assurer que le suffixe commence par '@' si ce n'est pas déjà le cas
                $normalized[] = str_starts_with($trimmed, '@') ? $trimmed : '@'.$trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $domains = $this->allowedDomains();
        $domainText = ! empty($domains) ? implode(', ', $domains) : 'autorisé';

        return [
            'email.ends_with' => "L'adresse email doit impérativement correspondre à un domaine autorisé ({$domainText}).",
            'requested_role.in' => 'Le rôle demandé n\'est pas un rôle interne autorisé.',
            'reason.min' => 'Veuillez motiver votre demande d\'accès plus en détail (au moins 10 caractères).',
        ];
    }
}
