<?php

namespace App\Http\Requests\Admin\Settings;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminLegalPublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasPermission(AdminPermission::SettingsLegal->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:255'],
            'content_markdown' => ['required', 'string', 'min:20'],
            'effective_date' => ['nullable', 'date'],
            'is_mandatory' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'max:10'],
        ];
    }
}
