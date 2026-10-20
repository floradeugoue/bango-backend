<?php

namespace App\Services\Admin;

use App\Models\Admin\AdminAuditLog;
use App\Models\Listing\Listing;
use App\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminListingExportService
{
    public const ALLOWED_COLUMNS = [
        'id' => 'Identifiant',
        'title' => 'Titre',
        'mode' => 'Mode',
        'kind' => 'Type de bien',
        'status' => 'Statut',
        'price' => 'Loyer / Prix',
        'charges' => 'Charges',
        'currency' => 'Devise',
        'city' => 'Ville',
        'neighbourhood' => 'Quartier',
        'bedrooms' => 'Chambres',
        'showers' => 'Douches',
        'area' => 'Surface (m²)',
        'agent_visited' => 'Visité par Agent',
        'owner_name' => 'Propriétaire',
        'owner_phone' => 'Téléphone',
        'published_at' => 'Date de publication',
        'created_at' => 'Date de création',
    ];

    /**
     * B.10. Produit un aperçu du nombre d'annonces correspondant aux filtres et des champs exportés.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function preview(array $filters = []): array
    {
        $query = Listing::query();

        // Point 20 : Filtrage selon les pays autorisés dans la session de l'opérateur
        $adminSession = request()?->attributes?->get('admin_session');
        if ($adminSession && ! empty($adminSession->allowed_countries)) {
            $allowedCountries = (array) $adminSession->allowed_countries;
            $query->whereHas('owner', function ($oq) use ($allowedCountries) {
                $oq->whereIn('country_code', $allowedCountries)
                    ->orWhereHas('country', function ($cq) use ($allowedCountries) {
                        $cq->whereIn('code', $allowedCountries)->orWhereIn('iso2', $allowedCountries);
                    });
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['city'])) {
            $query->whereRaw('LOWER(city) = ?', [strtolower(trim((string) $filters['city']))]);
        }
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        $count = $query->count();

        return [
            'count' => $count,
            'estimated_count' => $count,
            'columns' => array_values(self::ALLOWED_COLUMNS),
            'format' => 'CSV (UTF-8 avec BOM)',
        ];
    }

    /**
     * B.10 bis. Génère l'export CSV sécurisé des annonces.
     * Protection stricte contre l'injection de formules CSV et encodage UTF-8 BOM.
     *
     * @param  array<string, mixed>  $filters
     */
    public function export(array $filters, User $operator): StreamedResponse
    {
        $query = Listing::with(['owner'])
            ->orderByDesc('created_at');

        // Point 20 : Filtrage selon les pays autorisés dans la session de l'opérateur
        $adminSession = request()?->attributes?->get('admin_session');
        if ($adminSession && ! empty($adminSession->allowed_countries)) {
            $allowedCountries = (array) $adminSession->allowed_countries;
            $query->whereHas('owner', function ($oq) use ($allowedCountries) {
                $oq->whereIn('country_code', $allowedCountries)
                    ->orWhereHas('country', function ($cq) use ($allowedCountries) {
                        $cq->whereIn('code', $allowedCountries)->orWhereIn('iso2', $allowedCountries);
                    });
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['city'])) {
            $query->whereRaw('LOWER(city) = ?', [strtolower(trim((string) $filters['city']))]);
        }
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        $filename = 'export_annonces_'.now()->format('Ymd_His').'.csv';

        // Point 21 : Journalisation d'audit chaînée de l'export des annonces avec téléphones
        AdminAuditLog::logChainedEvent(
            event: 'listings.export_csv',
            operator: $operator,
            targetType: 'Listing',
            targetId: null,
            targetLabel: "Export CSV des annonces contenant les coordonnées des propriétaires ({$filename})",
            context: [
                'filters' => $filters,
                'filename' => $filename,
                'contains_owner_phone' => true,
            ],
            ip: request()?->ip(),
            userAgent: request()?->userAgent()
        );

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');

            // 1. Écriture du BOM UTF-8 pour Excel
            fwrite($output, "\xEF\xBB\xBF");

            // 2. Écriture des en-têtes
            fputcsv($output, array_values(self::ALLOWED_COLUMNS), ';');

            // 3. Écriture par chunk pour préserver la mémoire
            $query->chunk(250, function ($listings) use ($output) {
                foreach ($listings as $l) {
                    $row = [
                        $this->sanitizeCsvField($l->id),
                        $this->sanitizeCsvField($l->title),
                        $this->sanitizeCsvField($l->mode?->value ?? $l->mode),
                        $this->sanitizeCsvField($l->kind?->value ?? $l->kind),
                        $this->sanitizeCsvField($l->status?->value ?? $l->status),
                        $this->sanitizeCsvField((string) $l->price),
                        $this->sanitizeCsvField((string) $l->charges),
                        $this->sanitizeCsvField($l->currency),
                        $this->sanitizeCsvField($l->city),
                        $this->sanitizeCsvField($l->neighbourhood),
                        $this->sanitizeCsvField((string) $l->bedrooms),
                        $this->sanitizeCsvField((string) $l->showers),
                        $this->sanitizeCsvField((string) $l->area),
                        $l->agent_visited ? 'OUI' : 'NON',
                        $this->sanitizeCsvField($l->owner?->name ?? 'N/A'),
                        $this->sanitizeCsvField($l->owner?->phone ?? 'N/A'),
                        $l->published_at ? $l->published_at->format('Y-m-d H:i') : '',
                        $l->created_at ? $l->created_at->format('Y-m-d H:i') : '',
                    ];

                    fputcsv($output, $row, ';');
                }
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Neutralise les risques d'injection de formules dans les tableurs (=, +, -, @, tab, cr).
     */
    protected function sanitizeCsvField(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $trimmed = trim($value);

        if (in_array(substr($trimmed, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$trimmed;
        }

        return $trimmed;
    }
}
