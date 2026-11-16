<?php

namespace App\Http\Requests\Admin\Settings;

use App\Enums\Admin\AdminPermission;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminPricingChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasPermission(AdminPermission::SettingsPricing->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'price_id' => ['required', 'exists:subscription_prices,id'],
            'new_price_amount' => ['required', 'numeric', 'min:100', 'max:10000000'],
            'notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'legal_document_id' => [
                'required_without:terms_version',
                'nullable',
                Rule::exists('legal_documents', 'id')->where(function ($query) {
                    $query->where('slug', LegalDocumentService::TERMS_SLUG);
                }),
            ],
            'terms_version' => [
                'required_without:legal_document_id',
                'nullable',
                'string',
                'max:50',
                Rule::exists('legal_documents', 'version')->where(function ($query) {
                    $query->where('slug', LegalDocumentService::TERMS_SLUG);
                }),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'legal_document_id.required_without' => 'Tout changement de prix exige obligatoirement une nouvelle version des CGU (legal_document_id ou terms_version).',
            'terms_version.required_without' => 'Tout changement de prix exige obligatoirement une nouvelle version des CGU (legal_document_id ou terms_version).',
            'legal_document_id.exists' => 'Le document légal sélectionné est introuvable ou n\'est pas un document de type Conditions Générales d\'Utilisation (terms-of-service).',
            'terms_version.exists' => 'La version des CGU indiquée est introuvable pour les Conditions Générales d\'Utilisation (terms-of-service).',
        ];
    }
}
