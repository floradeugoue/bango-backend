<?php

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

class StoreModerationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'unique:moderation_rules,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'app_reason' => ['nullable', 'string', 'max:50'],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'default_action' => ['required', 'string', 'in:keep_online,hide,remove,warn_author,suspend_author'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
