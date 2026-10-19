<?php

namespace App\Http\Requests\Admin\Account;

use Illuminate\Foundation\Http\FormRequest;

class AdminAccountSuspendRequest extends FormRequest
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
            'duration' => ['required', 'string', 'in:7_days,30_days,indefinite'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
