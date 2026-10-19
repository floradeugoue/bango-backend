<?php

namespace App\Http\Requests\Admin\Settings;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasPermission(AdminPermission::SettingsInterests->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isPost = $this->isMethod('post');

        return [
            'id' => [$isPost ? 'required' : 'nullable', 'string', 'max:50', $isPost ? 'unique:interests,id' : ''],
            'name_fr' => [$isPost ? 'required' : 'nullable', 'string', 'max:100'],
            'name_en' => [$isPost ? 'required' : 'nullable', 'string', 'max:100'],
        ];
    }
}
