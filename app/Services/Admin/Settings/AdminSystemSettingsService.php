<?php

namespace App\Services\Admin\Settings;

use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\SystemConfiguration;
use App\Models\Geo\City;
use App\Models\Geo\Neighbourhood;
use App\Models\Geo\Operator;
use App\Models\Legal\LegalDocument;
use App\Models\Profile\Interest;
use App\Models\Subscription\PricingChangeProposal;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminSystemSettingsService
{
    // =========================================================================
    // 5.1. TARIFICATION
    // =========================================================================

    /**
     * Consultation des offres et grilles tarifaires existantes.
     *
     * @return array<string, mixed>
     */
    public function getPricingCatalog(): array
    {
        $products = SubscriptionProduct::with(['prices'])->orderBy('display_order')->get();
        $scheduledChanges = PricingChangeProposal::with(['price.product', 'proposer:id,name', 'approver:id,name'])
            ->where('status', 'scheduled')
            ->orderBy('effective_date', 'asc')
            ->get();

        return [
            'products' => $products,
            'scheduled_changes' => $scheduledChanges,
        ];
    }

    /**
     * Simulation en lecture seule de l'impact d'un changement de prix.
     * GARANTIE : Aucune modification en base de données.
     *
     * @return array<string, mixed>
     */
    public function previewPricingImpact(int $priceId, float $newAmount): array
    {
        $price = SubscriptionPrice::with('product')->findOrFail($priceId);

        $activeSubscriptions = Subscription::where('price_id', $price->id)
            ->where('status', 'active')
            ->count();

        $currentAmount = (float) $price->price_amount;
        $diffPerSubscriber = $newAmount - $currentAmount;
        $monthlyRevenueDelta = $diffPerSubscriber * $activeSubscriptions;

        return [
            'price_id' => $price->id,
            'product_name' => $price->product?->name ?? 'Produit inconnu',
            'cadence' => $price->cadence,
            'currency' => $price->currency,
            'current_price' => $currentAmount,
            'simulated_new_price' => $newAmount,
            'active_subscribers_impacted' => $activeSubscriptions,
            'delta_per_subscriber' => $diffPerSubscriber,
            'estimated_monthly_revenue_delta' => round($monthlyRevenueDelta, 2),
            'simulated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Programmation d'un changement de prix avec date d'effet différée (noticeDays).
     */
    public function proposePricingChange(
        int $priceId,
        float $newAmount,
        int $noticeDays,
        ?string $reason,
        User $operator
    ): PricingChangeProposal {
        return DB::transaction(function () use ($priceId, $newAmount, $noticeDays, $reason, $operator) {
            $price = SubscriptionPrice::findOrFail($priceId);
            $effectiveDate = Carbon::now()->addDays($noticeDays);

            $simulation = $this->previewPricingImpact($priceId, $newAmount);

            $proposal = PricingChangeProposal::create([
                'price_id' => $price->id,
                'subscription_price_id' => $price->id,
                'proposed_by_id' => $operator->id,
                'new_price_amount' => $newAmount,
                'notice_days' => $noticeDays,
                'effective_date' => $effectiveDate,
                'status' => 'scheduled',
                'reason' => $reason,
                'simulation_impact' => $simulation,
            ]);

            AdminAuditLog::logChainedEvent(
                event: 'pricing.change_scheduled',
                operator: $operator,
                targetType: 'PricingChangeProposal',
                targetId: (string) $proposal->id,
                targetLabel: "Programmation tarifaire {$price->product?->name} ({$newAmount} {$price->currency})",
                context: [
                    'price_id' => $price->id,
                    'new_amount' => $newAmount,
                    'notice_days' => $noticeDays,
                    'effective_date' => $effectiveDate->toIso8601String(),
                ]
            );

            return $proposal->load(['price.product', 'proposer']);
        });
    }

    // =========================================================================
    // 5.2. SEUILS AUTOMATIQUES DE MODÉRATION
    // =========================================================================

    /**
     * Consultation des seuils automatiques de signalement et masquage.
     *
     * @return array<string, mixed>
     */
    public function getModerationThresholds(): array
    {
        return [
            'auto_hide_report_count' => (int) (SystemConfiguration::getValue('thresholds.auto_hide_report_count') ?? SystemConfiguration::getValue('moderation.auto_hide_report_count', 5)),
            'auto_escalate_report_count' => (int) (SystemConfiguration::getValue('thresholds.auto_escalate_report_count') ?? SystemConfiguration::getValue('moderation.auto_escalate_report_count', 3)),
            'suspicious_velocity_threshold' => (int) (SystemConfiguration::getValue('thresholds.suspicious_velocity_threshold') ?? SystemConfiguration::getValue('moderation.suspicious_velocity_threshold', 20)),
            'kyc_escalation_risk_score' => (int) (SystemConfiguration::getValue('thresholds.kyc_escalation_risk_score') ?? SystemConfiguration::getValue('moderation.kyc_escalation_risk_score', 80)),
            'last_updated_at' => SystemConfiguration::where('group', 'thresholds')->latest('updated_at')->value('updated_at')?->toIso8601String(),
        ];
    }

    /**
     * Modification validée des seuils automatiques avec traçabilité d'audit.
     *
     * @param  array<string, mixed>  $thresholds
     * @return array<string, mixed>
     */
    public function updateModerationThresholds(array $thresholds, User $operator, ?string $reason = null): array
    {
        return DB::transaction(function () use ($thresholds, $operator, $reason) {
            $updated = [];

            foreach ($thresholds as $key => $value) {
                $rawKey = Str::after($key, 'thresholds.');
                $rawKey = Str::after($rawKey, 'moderation.');

                SystemConfiguration::setValue(
                    key: "thresholds.{$rawKey}",
                    value: (string) $value,
                    group: 'thresholds',
                    type: 'string',
                    description: "Seuil automatique : {$rawKey}",
                    operator: $operator
                );

                SystemConfiguration::setValue(
                    key: "moderation.{$rawKey}",
                    value: (string) $value,
                    group: 'thresholds',
                    type: 'string',
                    description: "Seuil automatique : {$rawKey}",
                    operator: $operator
                );

                $updated[$rawKey] = is_numeric($value) ? (int) $value : $value;
            }

            AdminAuditLog::logChainedEvent(
                event: 'settings.thresholds_updated',
                operator: $operator,
                targetType: 'SystemConfiguration',
                targetId: 'thresholds',
                targetLabel: 'Mise à jour des seuils de modération',
                context: [
                    'updated_thresholds' => $updated,
                    'reason' => $reason,
                ]
            );

            return array_merge($this->getModerationThresholds(), $updated);
        });
    }

    // =========================================================================
    // 5.3. OPÉRATEURS MOBILE MONEY
    // =========================================================================

    /**
     * Liste des opérateurs Mobile Money et statut de disponibilité.
     */
    public function getMobileMoneyOperators(): Collection
    {
        return Operator::with(['country:id,name,iso_code'])->get();
    }

    /**
     * Simulation de l'impact de la suspension d'un opérateur Mobile Money.
     *
     * @return array<string, mixed>
     */
    public function previewOperatorSuspension(int $operatorId): array
    {
        $operator = Operator::with('country')->findOrFail($operatorId);

        // Simulation : transactions sur cet opérateur dans les 30 derniers jours
        $recentTransactionsCount = DB::table('payment_transactions')
            ->where('provider', 'like', "%{$operator->code}%")
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $fallbackOperator = Operator::where('country_id', $operator->country_id)
            ->where('id', '!=', $operator->id)
            ->where('is_active', true)
            ->first();

        return [
            'operator' => [
                'id' => $operator->id,
                'name' => $operator->name,
                'code' => $operator->code,
                'is_active' => (bool) $operator->is_active,
                'country' => $operator->country?->name,
            ],
            'recent_30d_transactions_count' => $recentTransactionsCount,
            'fallback_operator' => $fallbackOperator ? [
                'id' => $fallbackOperator->id,
                'name' => $fallbackOperator->name,
                'code' => $fallbackOperator->code,
            ] : null,
            'has_automatic_fallback' => $fallbackOperator !== null,
            'simulated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Suspension contrôlée d'un opérateur Mobile Money avec bascule vers un fallback.
     */
    public function suspendOperator(
        int $operatorId,
        string $reason,
        ?int $fallbackOperatorId,
        User $operator
    ): Operator {
        return DB::transaction(function () use ($operatorId, $reason, $fallbackOperatorId, $operator) {
            $op = Operator::findOrFail($operatorId);

            if (! $op->is_active) {
                throw new DomainException("L'opérateur {$op->name} est déjà suspendu.");
            }

            $op->is_active = false;
            $op->suspended_at = Carbon::now();
            $op->suspension_reason = $reason;
            $op->suspended_by_id = $operator->id;
            $op->fallback_operator_id = $fallbackOperatorId;
            $op->save();

            AdminAuditLog::logChainedEvent(
                event: 'operator.suspended',
                operator: $operator,
                targetType: 'Operator',
                targetId: (string) $op->id,
                targetLabel: "Suspension de l'opérateur Mobile Money {$op->name}",
                context: [
                    'reason' => $reason,
                    'fallback_operator_id' => $fallbackOperatorId,
                ]
            );

            return $op->fresh();
        });
    }

    /**
     * Réactivation d'un opérateur Mobile Money suspendu.
     */
    public function resumeOperator(int $operatorId, User $operator): Operator
    {
        return DB::transaction(function () use ($operatorId, $operator) {
            $op = Operator::findOrFail($operatorId);

            if ($op->is_active) {
                throw new DomainException("L'opérateur {$op->name} est déjà actif.");
            }

            $op->is_active = true;
            $op->suspended_at = null;
            $op->suspension_reason = null;
            $op->suspended_by_id = null;
            $op->fallback_operator_id = null;
            $op->save();

            AdminAuditLog::logChainedEvent(
                event: 'operator.resumed',
                operator: $operator,
                targetType: 'Operator',
                targetId: (string) $op->id,
                targetLabel: "Réactivation de l'opérateur Mobile Money {$op->name}",
                context: [
                    'resumed_at' => now()->toIso8601String(),
                ]
            );

            return $op->fresh();
        });
    }

    // =========================================================================
    // 5.4. RÉFÉRENTIEL GÉOGRAPHIQUE (VILLES ET QUARTIERS)
    // =========================================================================

    /**
     * Consultation des villes avec compteurs.
     */
    public function getCities(): Collection
    {
        return City::withCount('neighbourhoods')->with('country:id,name,iso_code')->get();
    }

    /**
     * Consultation des quartiers par ville ou filtre.
     */
    public function getAreas(?string $cityIdOrSlug = null, ?string $status = null): Collection
    {
        $query = Neighbourhood::with('city:id,name');

        if ($cityIdOrSlug) {
            $query->whereHas('city', function ($q) use ($cityIdOrSlug) {
                if (is_numeric($cityIdOrSlug)) {
                    $q->where('id', $cityIdOrSlug);
                } else {
                    $q->where('name', 'like', "%{$cityIdOrSlug}%")
                        ->orWhere('slug', $cityIdOrSlug);
                }
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true)->where('is_ignored', false);
        } elseif ($status === 'ignored') {
            $query->where('is_ignored', true);
        }

        return $query->get();
    }

    /**
     * Ajout d'un quartier à une ville existante.
     */
    public function addArea(string $cityNameOrId, string $name, User $operator): Neighbourhood
    {
        return DB::transaction(function () use ($cityNameOrId, $name, $operator) {
            $city = is_numeric($cityNameOrId)
                ? City::findOrFail($cityNameOrId)
                : City::where('name', 'like', $cityNameOrId)->firstOrFail();

            $slug = Str::slug($name);

            // Vérifier les doublons
            $exists = Neighbourhood::where('city_id', $city->id)
                ->where(function ($q) use ($name, $slug) {
                    $q->where('name', 'like', $name)->orWhere('slug', $slug);
                })
                ->exists();

            if ($exists) {
                throw new DomainException("Le quartier '{$name}' existe déjà dans la ville de {$city->name}.");
            }

            $area = Neighbourhood::create([
                'city_id' => $city->id,
                'name' => $name,
                'slug' => $slug.'-'.uniqid(),
                'is_active' => true,
                'is_ignored' => false,
            ]);

            AdminAuditLog::logChainedEvent(
                event: 'geo.area_created',
                operator: $operator,
                targetType: 'Neighbourhood',
                targetId: (string) $area->id,
                targetLabel: "Création du quartier {$area->name} ({$city->name})",
                context: ['city_id' => $city->id, 'name' => $name]
            );

            return $area;
        });
    }

    /**
     * Modification d'un quartier existant sans altérer l'intégrité des annonces.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateArea(string $cityNameOrId, string $nameOrId, array $data, User $operator): Neighbourhood
    {
        return DB::transaction(function () use ($cityNameOrId, $nameOrId, $data, $operator) {
            $city = is_numeric($cityNameOrId)
                ? City::findOrFail($cityNameOrId)
                : City::where('name', 'like', $cityNameOrId)->firstOrFail();

            $area = is_numeric($nameOrId)
                ? Neighbourhood::where('city_id', $city->id)->where('id', $nameOrId)->firstOrFail()
                : Neighbourhood::where('city_id', $city->id)->where('name', 'like', $nameOrId)->firstOrFail();

            if (! empty($data['name'])) {
                $area->name = $data['name'];
            }
            if (isset($data['is_active'])) {
                $area->is_active = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($data['is_ignored'])) {
                $area->is_ignored = filter_var($data['is_ignored'], FILTER_VALIDATE_BOOLEAN);
            }
            $area->save();

            AdminAuditLog::logChainedEvent(
                event: 'geo.area_updated',
                operator: $operator,
                targetType: 'Neighbourhood',
                targetId: (string) $area->id,
                targetLabel: "Modification du quartier {$area->name}",
                context: $data
            );

            return $area;
        });
    }

    /**
     * Marquage d'un quartier comme ignoré des suggestions.
     */
    public function ignoreArea(string $cityNameOrId, string $nameOrId, bool $ignore, User $operator): Neighbourhood
    {
        return $this->updateArea($cityNameOrId, $nameOrId, ['is_ignored' => $ignore], $operator);
    }

    // =========================================================================
    // 5.5. CENTRES D'INTÉRÊT
    // =========================================================================

    /**
     * Liste des centres d'intérêt avec compteurs d'utilisateurs.
     */
    public function getInterests(): Collection
    {
        return Interest::withCount('users')->get();
    }

    /**
     * Création d'un centre d'intérêt.
     *
     * @param  array<string, mixed>  $data
     */
    public function createInterest(array $data, User $operator): Interest
    {
        return DB::transaction(function () use ($data, $operator) {
            $interest = Interest::create([
                'id' => Str::slug($data['id'] ?? $data['name_fr']),
                'name_fr' => $data['name_fr'],
                'name_en' => $data['name_en'] ?? $data['name_fr'],
            ]);

            AdminAuditLog::logChainedEvent(
                event: 'interest.created',
                operator: $operator,
                targetType: 'Interest',
                targetId: $interest->id,
                targetLabel: "Création centre d'intérêt {$interest->name_fr}",
                context: $data
            );

            return $interest;
        });
    }

    /**
     * Modification d'un centre d'intérêt existant.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateInterest(string $id, array $data, User $operator): Interest
    {
        return DB::transaction(function () use ($id, $data, $operator) {
            $interest = Interest::findOrFail($id);
            if (isset($data['name_fr'])) {
                $interest->name_fr = $data['name_fr'];
            }
            if (isset($data['name_en'])) {
                $interest->name_en = $data['name_en'];
            }
            $interest->save();

            AdminAuditLog::logChainedEvent(
                event: 'interest.updated',
                operator: $operator,
                targetType: 'Interest',
                targetId: $interest->id,
                targetLabel: "Mise à jour centre d'intérêt {$interest->name_fr}",
                context: $data
            );

            return $interest;
        });
    }

    // =========================================================================
    // 5.6. CGU ET VERSIONS LÉGALES
    // =========================================================================

    /**
     * Consultation des documents légaux et historique des versions.
     */
    public function getLegalDocuments(): Collection
    {
        return LegalDocument::with('author:id,name,email')
            ->orderBy('slug')
            ->orderBy('published_at', 'desc')
            ->get();
    }

    /**
     * Publication d'une nouvelle version des CGU ou politique légale.
     */
    public function publishLegalDocument(
        string $key,
        string $version,
        string $title,
        string $contentMarkdown,
        ?Carbon $effectiveDate,
        bool $isMandatory,
        User $operator,
        string $locale = 'fr'
    ): LegalDocument {
        return DB::transaction(function () use ($key, $version, $title, $contentMarkdown, $effectiveDate, $isMandatory, $operator, $locale) {
            $effDate = $effectiveDate ?? Carbon::now();

            // Création de la nouvelle version
            $doc = LegalDocument::create([
                'slug' => $key,
                'version' => $version,
                'locale' => $locale,
                'title' => $title,
                'content_markdown' => $contentMarkdown,
                'effective_date' => $effDate,
                'is_published' => true,
                'published_at' => Carbon::now(),
                'is_mandatory' => $isMandatory,
                'created_by' => $operator->id,
            ]);

            AdminAuditLog::logChainedEvent(
                event: 'legal.version_published',
                operator: $operator,
                targetType: 'LegalDocument',
                targetId: (string) $doc->id,
                targetLabel: "Publication {$title} version {$version}",
                context: [
                    'slug' => $key,
                    'version' => $version,
                    'is_mandatory' => $isMandatory,
                    'effective_date' => $effDate->toIso8601String(),
                ]
            );

            return $doc;
        });
    }

    // =========================================================================
    // 5.7. MAINTENANCE ET INCIDENTS
    // =========================================================================

    /**
     * Consultation de l'état opérationnel du service.
     *
     * @return array<string, mixed>
     */
    public function getServiceStatus(): array
    {
        return [
            'maintenance_mode' => (bool) SystemConfiguration::getValue('service.maintenance_mode', false),
            'maintenance_message' => SystemConfiguration::getValue('service.maintenance_message', 'Plateforme en maintenance programmée.'),
            'min_version_android' => SystemConfiguration::getValue('service.min_version_android', config('system.versions.android.min_version', '1.0.0')),
            'min_version_ios' => SystemConfiguration::getValue('service.min_version_ios', config('system.versions.ios.min_version', '1.0.0')),
            'incident_banner' => SystemConfiguration::getValue('service.incident_banner', null),
        ];
    }

    /**
     * Déclenchement d'actions sur l'état de service.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function executeServiceAction(string $action, array $params, User $operator): array
    {
        return DB::transaction(function () use ($action, $params, $operator) {
            switch ($action) {
                case 'toggle_maintenance':
                    $enabled = filter_var($params['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
                    $message = $params['message'] ?? 'Plateforme en cours de maintenance technique.';
                    SystemConfiguration::setValue('service.maintenance_mode', $enabled, 'maintenance', 'boolean', 'Statut du mode maintenance', $operator);
                    SystemConfiguration::setValue('service.maintenance_message', $message, 'maintenance', 'string', 'Message du mode maintenance', $operator);
                    break;

                case 'update_min_version':
                    if (! empty($params['min_version_android'])) {
                        SystemConfiguration::setValue('service.min_version_android', $params['min_version_android'], 'service', 'string', 'Version minimale Android', $operator);
                    }
                    if (! empty($params['min_version_ios'])) {
                        SystemConfiguration::setValue('service.min_version_ios', $params['min_version_ios'], 'service', 'string', 'Version minimale iOS', $operator);
                    }
                    break;

                case 'set_incident_banner':
                    $banner = [
                        'active' => true,
                        'message' => $params['message'] ?? 'Incident technique en cours de résolution.',
                        'severity' => $params['severity'] ?? 'warning', // info, warning, critical
                        'created_at' => now()->toIso8601String(),
                    ];
                    SystemConfiguration::setValue('service.incident_banner', $banner, 'service', 'json', 'Bandeau incident actif', $operator);
                    break;

                case 'clear_incident_banner':
                    SystemConfiguration::setValue('service.incident_banner', null, 'service', 'json', 'Bandeau incident révoqué', $operator);
                    break;

                default:
                    throw new DomainException("Action de service non supportée : {$action}");
            }

            AdminAuditLog::logChainedEvent(
                event: "service.action.{$action}",
                operator: $operator,
                targetType: 'SystemConfiguration',
                targetId: $action,
                targetLabel: "Exécution action service : {$action}",
                context: $params
            );

            return $this->getServiceStatus();
        });
    }

    // =========================================================================
    // 5.8. RÉFÉRENTIEL DES DÉLAIS LÉGAUX ET MÉTIER
    // =========================================================================

    /**
     * Consultation des délais légaux et métier configurés dans BANGO.
     *
     * @return array<string, mixed>
     */
    public function getDelays(): array
    {
        return [
            'legal_delays' => [
                [
                    'name' => 'Droit à l\'oubli RGPD',
                    'domain' => 'RGPD / Confidentialité',
                    'duration_days' => 30,
                    'unit' => 'jours',
                    'legal_source' => 'Article 12(3) RGPD',
                    'description' => 'Délai maximal de traitement d\'une demande d\'anonymisation ou de portabilité.',
                ],
                [
                    'name' => 'Conservation des journaux financiers',
                    'domain' => 'Comptabilité / Fiscalité',
                    'duration_years' => 5,
                    'unit' => 'années',
                    'legal_source' => 'Code de commerce & Code des impôts',
                    'description' => 'Obligation de conservation des écritures de paiement et remboursements.',
                ],
                [
                    'name' => 'Rétention des notes internes confidentielles',
                    'domain' => 'Support / Contentieux',
                    'duration_years' => 5,
                    'unit' => 'années',
                    'legal_source' => 'Prescription civile & commerciale',
                    'description' => 'Conservation obligatoire des notes internes et pièces probatoires.',
                ],
            ],
            'business_delays' => [
                [
                    'name' => 'Fenêtre de réouverture d\'un ticket résolu',
                    'domain' => 'Support Client',
                    'duration_days' => 7,
                    'unit' => 'jours',
                    'description' => 'Un ticket résolu ne peut être réouvert par l\'utilisateur que dans un délai de 7 jours.',
                ],
                [
                    'name' => 'SLA Contestation de modération',
                    'domain' => 'Modération',
                    'duration_hours' => 72,
                    'unit' => 'heures',
                    'description' => 'Délai cible de réexamen d\'un recours par un second modérateur.',
                ],
                [
                    'name' => 'Expiration des liens d\'export temporaires',
                    'domain' => 'Sécurité',
                    'duration_days' => 7,
                    'unit' => 'jours',
                    'description' => 'Durée de rétention des fichiers CSV d\'export avant purge automatique.',
                ],
                [
                    'name' => 'Préavis de modification tarifaire',
                    'domain' => 'Tarification',
                    'duration_days' => 30,
                    'unit' => 'jours',
                    'description' => 'Période minimale de préavis avant entrée en vigueur d\'un nouveau tarif d\'abonnement.',
                ],
            ],
        ];
    }
}
