<?php

namespace App\Services\Admin;

use App\Enums\Admin\AdminPermission;
use App\Models\Geo\Country;
use App\Models\Listing\Listing;
use App\Models\User;

class AdminCountryService
{
    /**
     * Codes des 5 pays officiellement couverts par BANGO.
     *
     * @var array<string>
     */
    public const COVERED_CODES = ['CM', 'SN', 'CI', 'GH', 'NG'];

    /**
     * Retourne la liste des pays couverts avec compteurs réels si autorisés.
     *
     * @return array<string, mixed>
     */
    public function getCoveredCountries(User $operator): array
    {
        $existing = Country::with('currency')
            ->where(function ($query) {
                $query->whereIn('code', self::COVERED_CODES)
                    ->orWhereIn('iso2', self::COVERED_CODES);
            })
            ->get();

        $canViewMetrics = $operator->hasPermission(AdminPermission::DashboardView->value);

        $defaultInfo = [
            'CM' => ['name' => 'Cameroun', 'phone_code' => '+237', 'currency' => 'XAF'],
            'SN' => ['name' => 'Sénégal', 'phone_code' => '+221', 'currency' => 'XOF'],
            'CI' => ['name' => 'Côte d\'Ivoire', 'phone_code' => '+225', 'currency' => 'XOF'],
            'GH' => ['name' => 'Ghana', 'phone_code' => '+233', 'currency' => 'GHS'],
            'NG' => ['name' => 'Nigeria', 'phone_code' => '+234', 'currency' => 'NGN'],
        ];

        $countriesList = [];

        foreach (self::COVERED_CODES as $code) {
            /** @var Country|null $record */
            $record = $existing->first(fn ($c) => strtoupper($c->code) === $code || strtoupper($c->iso2) === $code);

            $countryData = [
                'code' => $code,
                'name' => $record ? $record->name : ($defaultInfo[$code]['name'] ?? $code),
                'phone_code' => $record ? $record->phone_code : ($defaultInfo[$code]['phone_code'] ?? null),
                'currency' => $record?->currency ? $record->currency->code : ($defaultInfo[$code]['currency'] ?? null),
                'is_active' => $record ? (bool) $record->is_active : true,
            ];

            // Si l'opérateur a les droits, on ajoute les statistiques réelles
            if ($canViewMetrics && $record) {
                $countryData['metrics'] = [
                    'users_count' => User::where('country_id', $record->id)->count(),
                    'listings_count' => Listing::where('country_id', $record->id)->count(),
                ];
            }

            $countriesList[] = $countryData;
        }

        return [
            'total_covered' => count($countriesList),
            'countries' => $countriesList,
        ];
    }
}
