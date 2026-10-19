<?php

namespace App\Http\Requests\Admin\Audit;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminRoleConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasPermission(AdminPermission::RolesConfirm->value) || $user->hasPermission(AdminPermission::RolesManage->value));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_id' => ['nullable', 'exists:admin_approvals,id'],
            'decision' => ['required', 'string', 'in:approve,reject'],
            'decision_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
