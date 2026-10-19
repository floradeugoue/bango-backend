<?php

namespace App\Http\Requests\Admin\Settings;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
