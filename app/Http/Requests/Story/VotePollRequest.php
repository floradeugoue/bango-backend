<?php

namespace App\Http\Requests\Story;

use Illuminate\Foundation\Http\FormRequest;

class VotePollRequest extends FormRequest
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
            'option_index' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }
}
