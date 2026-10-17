<?php

namespace App\Services\Admin;

use App\Enums\Support\ReportReason;
use App\Models\Admin\AdminAuditLog;
use App\Models\Support\ModerationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class ModerationRuleService
{
    /**
     * Retourne la liste de toutes les règles de modération configurées.
     */
    public function getRules(?bool $activeOnly = null): Collection
    {
        $query = ModerationRule::query()->with(['creator:id,name,email', 'updater:id,name,email']);

        if ($activeOnly !== null) {
            $query->where('is_active', $activeOnly);
        }

        return $query->orderBy('code', 'asc')->get();
    }

    /**
     * Crée une nouvelle règle de modération.
     *
     * @param  array<string, mixed>  $data
     */
    public function createRule(array $data, User $operator): ModerationRule
    {
        if (ModerationRule::where('code', $data['code'])->exists()) {
            throw new InvalidArgumentException("Une règle avec le code '{$data['code']}' existe déjà.");
        }

        $rule = ModerationRule::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'app_reason' => $data['app_reason'] ?? null,
            'severity' => $data['severity'] ?? 'medium',
            'default_action' => $data['default_action'] ?? 'hide',
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $operator->id,
            'updated_by' => $operator->id,
        ]);

        AdminAuditLog::logEvent('moderation.rule.created', $operator, request()->ip(), request()->userAgent(), [
            'code' => $rule->code,
            'name' => $rule->name,
        ]);

        return $rule;
    }

    /**
     * Met à jour une règle de modération existante.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRule(string $reasonCode, array $data, User $operator): ModerationRule
    {
        /** @var ModerationRule $rule */
        $rule = ModerationRule::where('code', $reasonCode)->firstOrFail();

        $rule->fill(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'app_reason' => $data['app_reason'] ?? null,
            'severity' => $data['severity'] ?? null,
            'default_action' => $data['default_action'] ?? null,
        ]));

        if (isset($data['is_active'])) {
            $rule->is_active = (bool) $data['is_active'];
        }

        $rule->updated_by = $operator->id;
        $rule->save();

        AdminAuditLog::logEvent('moderation.rule.updated', $operator, request()->ip(), request()->userAgent(), [
            'code' => $rule->code,
            'changes' => $data,
        ]);

        return $rule;
    }

    /**
     * Retourne la table de correspondance officielle entre les motifs de l'application et les motifs internes.
     *
     * @return array<string, mixed>
     */
    public function getAppReasonsMapping(): array
    {
        $appReasons = ReportReason::cases();
        $mapping = [];

        foreach ($appReasons as $reason) {
            $rule = ModerationRule::where('app_reason', $reason->value)
                ->orWhere('code', $reason->value)
                ->first();

            $mapping[] = [
                'app_reason_code' => $reason->value,
                'app_reason_label' => $reason->label(),
                'internal_rule_code' => $rule?->code ?? $reason->value,
                'internal_rule_name' => $rule?->name ?? $reason->label(),
                'severity' => $rule?->severity ?? 'medium',
                'default_action' => $rule?->default_action ?? 'hide',
                'is_active' => $rule ? $rule->is_active : true,
            ];
        }

        return $mapping;
    }
}
