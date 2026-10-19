<?php

namespace App\Http\Requests\Admin\Settings;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminThresholdsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasPermission(AdminPermission::SettingsThresholds->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'thresholds' => ['nullable', 'array'],
            'thresholds.*' => ['nullable'],
            'auto_hide_report_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'auto_flag_severity_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'auto_escalate_report_count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sla_moderation_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'fraud_risk_score_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'suspicious_velocity_threshold' => ['nullable', 'integer', 'min:1', 'max:500'],
            'kyc_escalation_risk_score' => ['nullable', 'integer', 'min:1', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
