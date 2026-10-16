<?php

namespace App\Http\Requests\Page;

use App\Enums\Page\PageRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePageMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in([PageRole::Owner->value, PageRole::Editor->value, PageRole::Moderator->value])],
        ];
    }
}
