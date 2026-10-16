<?php

namespace App\Http\Requests\Page;

use Illuminate\Foundation\Http\FormRequest;

class TransferOwnershipRequest extends FormRequest
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
            'new_owner_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
