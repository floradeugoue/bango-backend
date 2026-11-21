<?php

namespace App\Http\Requests\Support;

use App\Enums\Support\ReportReason;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');
        // Tolérance et normalisation pour les libellés provisoires ou envoyés en texte libre par l'app mobile
        if ($reason === 'minor' || $reason === 'child') {
            $this->merge(['reason' => ReportReason::ChildSafety->value]);
        } elseif ($reason === 'sexual' || $reason === 'nudity') {
            $this->merge(['reason' => ReportReason::SexualContent->value]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', 'in:post,post_comment,reel,reel_comment,listing,message,user,page'],
            'target_id' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
