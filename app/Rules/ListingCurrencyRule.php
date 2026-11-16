<?php

namespace App\Rules;

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ListingCurrencyRule implements ValidationRule
{
    /**
     * Valide que la valeur fournie est un code ISO 4217 valide parmi les devises des pays ouverts.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen(trim($value)) !== 3) {
            $fail("Le champ {$attribute} doit être un code ISO 4217 de 3 lettres.");

            return;
        }

        $code = strtoupper(trim($value));
        $allowed = static::allowedCurrencies();

        if (! in_array($code, $allowed, true)) {
            $fail("La devise {$value} n'est pas acceptée. Veuillez renseigner un code ISO 4217 valide parmi les devises des pays ouverts (".implode(', ', $allowed).').');
        }
    }

    /**
     * Récupère la liste des devises autorisées pour les pays ouverts.
     *
     * @return list<string>
     */
    public static function allowedCurrencies(): array
    {
        try {
            $codes = Currency::query()
                ->where('is_active', true)
                ->whereHas('countries', fn ($q) => $q->where('is_active', true))
                ->pluck('code')
                ->map(fn ($c) => strtoupper(trim((string) $c)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($codes)) {
                return $codes;
            }

            $activeCurrencyCodes = Currency::query()
                ->where('is_active', true)
                ->pluck('code')
                ->map(fn ($c) => strtoupper(trim((string) $c)))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($activeCurrencyCodes)) {
                return $activeCurrencyCodes;
            }
        } catch (\Throwable) {
            // En cas d'erreur de schéma ou si les tables ne sont pas encore migrées
        }

        return ['XAF', 'XOF', 'GHS', 'NGN', 'KES'];
    }

    /**
     * Déduit la devise par défaut depuis le compte de l'utilisateur ou la ville de l'annonce.
     */
    public static function deduceForUser(?User $user, ?string $city = null): string
    {
        if ($user) {
            // 1. Pays associé directement via relation country ou country_id
            if ($user->relationLoaded('country') && $user->country) {
                if ($user->country->relationLoaded('currency') && $user->country->currency?->code) {
                    return strtoupper((string) $user->country->currency->code);
                }
                if ($user->country->currency_id) {
                    try {
                        $curr = Currency::find($user->country->currency_id);
                        if ($curr?->code) {
                            return strtoupper((string) $curr->code);
                        }
                    } catch (\Throwable) {
                        // ignore
                    }
                }
            } elseif ($user->country_id) {
                try {
                    $country = Country::with('currency')->find($user->country_id);
                    if ($country?->currency?->code) {
                        return strtoupper((string) $country->currency->code);
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }

            // 2. Code pays sur l'utilisateur (country_code, ex: 'CM', 'CI', 'SN')
            if (! empty($user->country_code)) {
                $countryCode = strtoupper(trim((string) $user->country_code));
                try {
                    $country = Country::where('code', $countryCode)
                        ->orWhere('iso2', $countryCode)
                        ->with('currency')
                        ->first();

                    if ($country?->currency?->code) {
                        return strtoupper((string) $country->currency->code);
                    }
                } catch (\Throwable) {
                    // Fallback statique
                }

                $staticCurrency = static::currencyForCountryCode($countryCode);
                if ($staticCurrency) {
                    return $staticCurrency;
                }
            }
        }

        // 3. Déduction par la ville (ex: Douala -> CM -> XAF, Abidjan -> CI -> XOF)
        if (! empty($city)) {
            $cityName = trim($city);
            try {
                $cityModel = City::where('name', $cityName)
                    ->with('country.currency')
                    ->first();

                if ($cityModel?->country?->currency?->code) {
                    return strtoupper((string) $cityModel->country->currency->code);
                }
            } catch (\Throwable) {
                // ignore
            }

            $staticCityCurrency = static::currencyForCityName($cityName);
            if ($staticCityCurrency) {
                return $staticCityCurrency;
            }
        }

        return (string) config('payment.currency', 'XAF');
    }

    /**
     * Mappage statique pays -> devise (devises des pays africains & zone euro).
     */
    public static function currencyForCountryCode(string $code): ?string
    {
        return match (strtoupper(trim($code))) {
            'CM', 'CMR', 'GA', 'GAB', 'CG', 'COG', 'CF', 'CAF', 'TD', 'TCD', 'GQ', 'GNQ' => 'XAF',
            'CI', 'CIV', 'SN', 'SEN', 'BJ', 'BEN', 'TG', 'TGO', 'ML', 'MLI', 'BF', 'BFA', 'NE', 'NER', 'GW', 'GNB' => 'XOF',
            'GH', 'GHA' => 'GHS',
            'NG', 'NGA' => 'NGN',
            'KE', 'KEN' => 'KES',
            'GN', 'GIN' => 'GNF',
            'FR', 'FRA', 'BE', 'BEL', 'DE', 'DEU', 'IT', 'ITA', 'ES', 'ESP', 'PT', 'PRT' => 'EUR',
            default => null,
        };
    }

    /**
     * Mappage statique villes courantes -> devise.
     */
    public static function currencyForCityName(string $city): ?string
    {
        $normalized = mb_strtolower(trim($city));

        return match ($normalized) {
            'douala', 'yaoundé', 'yaounde', 'bafoussam', 'garoua', 'kribi', 'bamenda', 'libreville', 'port-gentil', 'brazzaville', 'pointe-noire' => 'XAF',
            'abidjan', 'yamoussoukro', 'bouaké', 'bouake', 'san-pédro', 'san-pedro', 'dakar', 'thiès', 'thies', 'cotonou', 'porto-novo', 'lomé', 'lome' => 'XOF',
            'accra', 'kumasi' => 'GHS',
            'lagos', 'abuja' => 'NGN',
            'nairobi', 'mombasa' => 'KES',
            'conakry' => 'GNF',
            'paris', 'lyon', 'marseille', 'bordeaux' => 'EUR',
            default => null,
        };
    }
}
