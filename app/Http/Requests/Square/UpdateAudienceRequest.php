<?php

namespace App\Http\Requests\Square;

use App\Enums\Square\PostAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAudienceRequest extends FormRequest
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
            'audience' => ['required', 'string', Rule::in(PostAudience::values())],
        ];
    }

    public function audience(): string
    {
        return (string) $this->validated('audience');
    }
}
