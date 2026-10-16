<?php

namespace App\Http\Requests\Page;

use App\Enums\Page\PageRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvitePageMemberRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', Rule::in([PageRole::Editor->value, PageRole::Moderator->value, PageRole::Owner->value])],
        ];
    }
}
