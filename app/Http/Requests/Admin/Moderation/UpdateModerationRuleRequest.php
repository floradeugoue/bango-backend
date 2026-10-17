<?php

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

class UpdateModerationRuleRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'app_reason' => ['nullable', 'string', 'max:50'],
            'severity' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'default_action' => ['nullable', 'string', 'in:keep_online,hide,remove,warn_author,suspend_author'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
