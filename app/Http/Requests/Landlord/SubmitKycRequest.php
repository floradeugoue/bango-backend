<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SubmitKycRequest extends FormRequest
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
            'full_name' => ['nullable', 'string', 'max:150'],
            'requested_badge' => ['nullable', 'string', 'in:landlord,agent,double'],
            'id_type' => ['nullable', 'string', 'in:cni,passport,residence_permit'],
            'id_number' => ['nullable', 'string', 'max:64'],
            'expires_on' => ['nullable', 'date'],
            'property_right' => ['nullable', 'string', 'in:owner,representative,deed'],
            'deed_type' => ['nullable', 'string', 'max:64'],
            'files' => ['nullable', 'array'],
            'identity_front' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,pdf'],
            'identity_back' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,pdf'],
            'selfie' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp'],
            'liveness_frames' => ['nullable', 'array', 'size:2'],
            'liveness_frames.*' => ['file', 'max:10240', 'mimes:jpeg,jpg,png,webp'],
            'property_deed' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,pdf'],
            'property_deeds' => ['nullable', 'array'],
            'property_deeds.*' => ['file', 'max:10240', 'mimes:jpeg,jpg,png,webp,pdf'],
            'deed' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,pdf'],
        ];
    }

    public function messages(): array
    {
        return [
            'identity_front.max' => 'La pièce d\'identité recto ne doit pas dépasser 10 Mo.',
            'identity_front.mimes' => 'Format accepté pour le recto : JPG, PNG, WEBP ou PDF.',
            'selfie.mimes' => 'Le selfie doit être une image (JPG, PNG ou WEBP).',
            'liveness_frames.size' => 'Exactement deux captures de vivacité sont attendues (tête à gauche et tête à droite).',
            'liveness_frames.*.mimes' => 'Chaque capture de vivacité doit être une image (JPG, PNG ou WEBP).',
        ];
    }
}
