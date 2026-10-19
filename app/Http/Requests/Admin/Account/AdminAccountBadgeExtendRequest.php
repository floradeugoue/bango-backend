<?php

namespace App\Http\Requests\Admin\Account;

use Illuminate\Foundation\Http\FormRequest;

class AdminAccountBadgeExtendRequest extends FormRequest
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
            'badge' => ['required', 'string', 'max:64'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
