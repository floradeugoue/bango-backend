<?php

namespace App\Http\Requests\Page;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPageCertificationRequest extends FormRequest
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
            'rccm_number' => ['nullable', 'string', 'max:128'],
            'nif_number' => ['nullable', 'string', 'max:128'],
            'legal_representative_name' => ['nullable', 'string', 'max:255'],
            'legal_representative_id_type' => ['nullable', 'string', 'in:cni,passport,residence_permit'],
            'legal_representative_id_number' => ['nullable', 'string', 'max:64'],
            'rccm_doc' => ['nullable', 'file', 'mimes:jpeg,png,webp,pdf', 'max:10240'],
            'nif_doc' => ['nullable', 'file', 'mimes:jpeg,png,webp,pdf', 'max:10240'],
            'id_doc' => ['nullable', 'file', 'mimes:jpeg,png,webp,pdf', 'max:10240'],
            'extra_doc' => ['nullable', 'file', 'mimes:jpeg,png,webp,pdf', 'max:10240'],
        ];
    }
}
