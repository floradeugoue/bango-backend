<?php

namespace App\Http\Requests\Admin\Audit;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminAuditEditAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasPermission(AdminPermission::AuditView->value) || $user->hasPermission(AdminPermission::DashboardView->value));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attempted_changes' => ['required', 'array'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
