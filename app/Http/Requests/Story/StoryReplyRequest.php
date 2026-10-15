<?php

namespace App\Http\Requests\Story;

use App\Enums\Story\StoryReplyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoryReplyRequest extends FormRequest
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
            'type' => ['sometimes', 'string', Rule::in(StoryReplyType::values())],
            'text' => ['required_if:type,text', 'nullable', 'string', 'max:1000'],
            'reaction' => ['required_if:type,reaction', 'nullable', 'string', 'max:16'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'slide_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
