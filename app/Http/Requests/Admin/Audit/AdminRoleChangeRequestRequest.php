<?php

namespace App\Http\Requests\Admin\Audit;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminRoleChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasPermission(AdminPermission::RolesRequest->value) || $user->hasPermission(AdminPermission::RolesManage->value));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'role_slug' => ['required', 'string', 'exists:roles,slug'],
            'action' => ['required', 'string', 'in:assign,revoke'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
