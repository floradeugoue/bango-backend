<?php

namespace App\Http\Requests\Admin\Settings;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminOperatorSuspendRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasPermission(AdminPermission::SettingsOperators->value);
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('id') && ! $this->has('operator_id')) {
            $this->merge(['operator_id' => $this->route('id')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'operator_id' => ['required', 'exists:operators,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'fallback_operator_id' => ['nullable', 'exists:operators,id', 'different:operator_id'],
        ];
    }
}
