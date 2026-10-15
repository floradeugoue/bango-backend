<?php

namespace App\Http\Requests\Story;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCloseListRequest extends FormRequest
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
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
