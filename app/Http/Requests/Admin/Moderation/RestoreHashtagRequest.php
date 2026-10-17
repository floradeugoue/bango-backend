<?php

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

class RestoreHashtagRequest extends FormRequest
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
            'hashtag' => ['required', 'string', 'max:100'],
        ];
    }
}
