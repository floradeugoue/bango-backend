<?php

namespace App\Http\Requests\Admin\Stats;

use App\Enums\Admin\AdminPermission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminScheduledReportUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasPermission(AdminPermission::StatsReports->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'cadence' => ['nullable', 'string', 'in:daily,weekly,monthly'],
            'recipients' => ['nullable', 'array', 'min:1'],
            'recipients.*' => ['email'],
            'metrics' => ['nullable', 'array'],
            'format' => ['nullable', 'string', 'in:pdf,csv,json'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
