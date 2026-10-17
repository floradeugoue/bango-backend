<?php

namespace App\Http\Requests\Admin\Shell;

use Illuminate\Foundation\Http\FormRequest;

class AdminSearchRequest extends FormRequest
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
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'category' => ['nullable', 'string', 'in:all,comptes,annonces,paiements,tickets'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'Le terme de recherche est requis.',
            'q.min' => 'Le terme de recherche doit comporter au moins 2 caractères.',
            'category.in' => 'La catégorie demandée est invalide.',
        ];
    }
}
