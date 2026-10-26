<?php

namespace App\Http\Resources\Support;

use App\Models\Support\Report;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Report
 */
class ReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'target_type' => Report::resolveShortType($this->reportable_type),
            'target_id' => (string) $this->reportable_id,
            'reason' => [
                'value' => $this->reason->value,
                'label' => $this->reason->label(),
            ],
            'details' => $this->details,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'action_taken' => $this->action_taken,
            'reexamination_requested' => (bool) ($this->metadata['reexamination_requested'] ?? false),
            'reexamined_at' => $this->metadata['reexamined_at'] ?? null,
            'target' => $this->resolveTargetInfo(),
            'decision' => $this->resolveDecisionInfo(),
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * Résout les informations résumées de la cible signalée ({label, thumbnail_url}).
     *
     * @return array{label: string, thumbnail_url: ?string}
     */
    protected function resolveTargetInfo(): array
    {
        // 1. Label stocké dans les métadonnées lors de la création
        $label = $this->metadata['target_label'] ?? null;
        $thumbnailUrl = $this->metadata['target_thumbnail_url'] ?? null;

        // 2. Résolution dynamique si le modèle cible est chargé ou accessible
        $target = $this->relationLoaded('reportable')
            ? $this->reportable
            : $this->reportable()->first();

        if ($target) {
            if (! $label) {
                if (method_exists($target, 'getTitle')) {
                    $label = $target->getTitle();
                } elseif (! empty($target->title)) {
                    $label = (string) $target->title;
                } elseif (! empty($target->name)) {
                    $label = (string) $target->name;
                } elseif (! empty($target->display_name)) {
                    $label = (string) $target->display_name;
                } elseif (! empty($target->body)) {
                    $label = mb_substr((string) $target->body, 0, 80);
                } elseif (! empty($target->caption)) {
                    $label = mb_substr((string) $target->caption, 0, 80);
                }
            }

            if (! $thumbnailUrl) {
                if ($target instanceof \App\Models\Square\Post) {
                    $media = $target->relationLoaded('media') ? $target->media : $target->media()->get();
                    $thumbnailUrl = $media->first()?->media_url;
                } elseif ($target instanceof \App\Models\Reel\Reel) {
                    $thumbnailUrl = $target->thumbnail_url;
                } elseif ($target instanceof \App\Models\Listing\Listing) {
                    $photos = $target->relationLoaded('photos') ? $target->photos : $target->photos()->get();
                    $firstPhoto = $photos->first();
                    $thumbnailUrl = $firstPhoto?->thumbnail_url ?? $firstPhoto?->url;
                } elseif ($target instanceof \App\Models\User) {
                    $thumbnailUrl = $target->avatar_url;
                } elseif ($target instanceof \App\Models\Page\Page) {
                    $thumbnailUrl = $target->logo_url;
                }
            }
        }

        if (! $label) {
            $shortType = Report::resolveShortType($this->reportable_type);
            $label = ucfirst($shortType).' #'.$this->reportable_id;
        }

        return [
            'label' => (string) $label,
            'thumbnail_url' => $thumbnailUrl ? (string) $thumbnailUrl : null,
        ];
    }

    /**
     * Résout les détails de la décision rendue au signalant sans jamais divulguer l'identité du modérateur.
     *
     * @return array{action: ?string, decided_at: ?string, rule: ?array{label: string, body: string, url: string}, consequences: array<int, string>}|null
     */
    protected function resolveDecisionInfo(): ?array
    {
        $decision = $this->relationLoaded('decisions')
            ? $this->decisions->first()
            : $this->decisions()->first();

        // Si aucun enregistrement de décision formel n'existe et que le signalement n'est pas résolu/rejeté
        if (! $decision && ! $this->resolved_at && ! $this->action_taken) {
            return null;
        }

        $action = $decision?->action ?? $this->action_taken;
        $decidedAt = ($decision?->created_at ?? $this->resolved_at)?->toIso8601String();

        // 1. Règle appliquée
        $ruleData = null;
        $decisionMeta = $decision?->metadata ?? [];
        $ruleCode = $decisionMeta['rule_code'] ?? $decisionMeta['rule'] ?? null;

        $ruleModel = null;
        if ($ruleCode) {
            $ruleModel = \App\Models\Support\ModerationRule::where('code', $ruleCode)->first();
        }

        if (! $ruleModel && $this->reason) {
            $ruleModel = \App\Models\Support\ModerationRule::where('app_reason', $this->reason->value)->first();
        }

        if ($ruleModel) {
            $ruleData = [
                'label' => $ruleModel->name,
                'body' => $ruleModel->description,
                'url' => config('app.url').'/community-guidelines#'.$ruleModel->code,
            ];
        } elseif ($this->reason) {
            $ruleData = [
                'label' => $this->reason->label(),
                'body' => 'Règle relative aux signalements de type '.$this->reason->label().'.',
                'url' => config('app.url').'/community-guidelines',
            ];
        }

        // 2. Conséquences / Suites données (ex: "badge Agent suspendu 30 jours", "11 personnes en contact prévenues")
        $consequences = [];
        if (! empty($decisionMeta['consequences']) && is_array($decisionMeta['consequences'])) {
            $consequences = array_values(array_map('strval', $decisionMeta['consequences']));
        } else {
            // Déduction de suites claires et protectrices selon l'action
            switch ($action) {
                case 'suspend_author':
                    $durationDays = $decisionMeta['suspension_days'] ?? 30;
                    $consequences[] = "Compte auteur et privilèges suspendus {$durationDays} jours";
                    $consequences[] = 'Contenu retiré de la visibilité publique';
                    break;

                case 'remove':
                    $consequences[] = 'Contenu supprimé définitivement';
                    break;

                case 'hide':
                    $consequences[] = 'Contenu masqué et retiré des flux de diffusion';
                    break;

                case 'warn_author':
                    $consequences[] = 'Avertissement officiel notifié à l\'auteur';
                    break;

                case 'keep_online':
                    $consequences[] = 'Contenu maintenu en ligne suite à vérification de conformité';
                    break;

                default:
                    if ($action) {
                        $consequences[] = 'Mesure appliquée : '.ucfirst(str_replace('_', ' ', (string) $action));
                    }
                    break;
            }

            if (! empty($decisionMeta['notified_contacts_count'])) {
                $consequences[] = "{$decisionMeta['notified_contacts_count']} personnes en contact prévenues";
            }
        }

        return [
            'action' => $action,
            'decided_at' => $decidedAt,
            'rule' => $ruleData,
            'consequences' => $consequences,
        ];
    }
}
