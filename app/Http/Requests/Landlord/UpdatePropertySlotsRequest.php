<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertySlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slots' => ['nullable', 'array'],
            'slots.*.start_at' => ['required_with:slots', 'date'],
            'slots.*.end_at' => ['required_with:slots', 'date', 'after:slots.*.start_at'],
            'slots.*.capacity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'days' => ['nullable', 'array'],
            'days.*.day' => ['required_with:days', 'integer', 'between:0,6'],
            'days.*.from' => ['required_with:days', 'string'],
            'days.*.to' => ['required_with:days', 'string'],
            'days.*.closed' => ['nullable', 'boolean'],
            'slot_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:120'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'slots.*.start_at.required_with' => 'La date de début est requise pour chaque créneau.',
            'slots.*.end_at.after' => 'L\'heure de fin doit être postérieure à l\'heure de début.',
        ];
    }
}
