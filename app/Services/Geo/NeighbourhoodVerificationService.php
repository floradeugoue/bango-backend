<?php

namespace App\Services\Geo;

use App\Exceptions\Geo\NeighbourhoodNotVerifiedException;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Models\User;
use Illuminate\Support\Str;

class NeighbourhoodVerificationService
{
    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * Rayon maximal autorisé (en km) entre la position de l'appareil et le centre du quartier.
     */
    public function maxDistanceKm(): float
    {
        return (float) config('geo.neighbourhood_verification.max_distance_km', 3.0);
    }

    /**
     * Calcule la distance orthodromique (Haversine) en kilomètres entre deux coordonnées GPS.
     */
    public function haversineDistanceKm(float $latFrom, float $lngFrom, float $latTo, float $lngTo): float
    {
        $latFromRad = deg2rad($latFrom);
        $lngFromRad = deg2rad($lngFrom);
        $latToRad = deg2rad($latTo);
        $lngToRad = deg2rad($lngTo);

        $latDelta = $latToRad - $latFromRad;
        $lngDelta = $lngToRad - $lngFromRad;

        $haversine = (sin($latDelta / 2) ** 2)
            + cos($latFromRad) * cos($latToRad) * (sin($lngDelta / 2) ** 2);

        $angle = 2 * asin(min(1.0, sqrt($haversine)));

        return $angle * self::EARTH_RADIUS_KM;
    }

    /**
     * Vérifie si une position (latitude, longitude) se situe dans le rayon autorisé autour du quartier.
     *
     * @return array{verified: bool, distance_km: ?float, reason: ?string}
     */
    public function verifyPositionForNeighbourhood(
        Neighbourhood $neighbourhood,
        ?float $latitude,
        ?float $longitude,
        ?float $accuracy = null
    ): array {
        if ($latitude === null || $longitude === null) {
            return [
                'verified' => false,
                'distance_km' => null,
                'reason' => 'coordinates_missing',
            ];
        }

        if ($neighbourhood->lat === null || $neighbourhood->lng === null) {
            return [
                'verified' => false,
                'distance_km' => null,
                'reason' => 'neighbourhood_coordinates_missing',
            ];
        }

        $distanceKm = $this->haversineDistanceKm(
            $latitude,
            $longitude,
            (float) $neighbourhood->lat,
            (float) $neighbourhood->lng
        );

        if ($distanceKm > $this->maxDistanceKm()) {
            return [
                'verified' => false,
                'distance_km' => $distanceKm,
                'reason' => 'too_far',
            ];
        }

        return [
            'verified' => true,
            'distance_km' => $distanceKm,
            'reason' => null,
        ];
    }

    /**
     * Résout et vérifie les mises à jour de localisation (pays, ville, quartier) pour un profil utilisateur.
     * Ne retourne jamais les coordonnées GPS dans le tableau de mise à jour persisté.
     *
     * @param  array<string, mixed>  $input
     * @return array{updates: array<string, mixed>, neighbourhood_just_verified: bool}
     *
     * @throws NeighbourhoodNotVerifiedException
     */
    public function resolveProfileLocationUpdates(User $user, array $input): array
    {
        $updates = [];
        $neighbourhoodJustVerified = false;

        // 1. Résolution du pays
        $countryCodeInput = $input['countryCode'] ?? ($input['country_code'] ?? null);
        $countryIdInput = $input['countryId'] ?? ($input['country_id'] ?? null);

        if (is_string($countryCodeInput) && trim($countryCodeInput) !== '') {
            $iso2 = strtoupper(trim($countryCodeInput));
            $updates['country_code'] = $iso2;
            $country = Country::where('iso2', $iso2)->first();
            if ($country) {
                $updates['country_id'] = $country->id;
            }
        }

        if ($countryIdInput !== null && $countryIdInput !== '') {
            $updates['country_id'] = (int) $countryIdInput;
            $country = Country::find((int) $countryIdInput);
            if ($country) {
                $updates['country_code'] = $country->iso2;
            }
        }

        // 2. Résolution de la ville
        $hasCityKey = array_key_exists('city', $input)
            || array_key_exists('cityId', $input)
            || array_key_exists('city_id', $input);

        $cityIdInput = $input['cityId'] ?? ($input['city_id'] ?? null);
        $cityNameInput = array_key_exists('city', $input) && is_string($input['city'])
            ? trim($input['city'])
            : null;

        $cityExplicitlyProvided = ($cityIdInput !== null && $cityIdInput !== '')
            || ($cityNameInput !== null && $cityNameInput !== '');

        $cityExplicitlyCleared = $hasCityKey && ! $cityExplicitlyProvided;

        $resolvedCity = null;
        if ($cityExplicitlyProvided) {
            $resolvedCity = $this->resolveCity(
                $cityIdInput !== null && $cityIdInput !== '' ? (int) $cityIdInput : null,
                $cityNameInput
            );

            if ($resolvedCity) {
                $updates['city_id'] = $resolvedCity->id;
                $updates['city'] = $resolvedCity->name;
            } else {
                $updates['city_id'] = null;
                $updates['city'] = $cityNameInput;
            }
        } elseif ($cityExplicitlyCleared) {
            $updates['city_id'] = null;
            $updates['city'] = null;
        }

        $currentCityName = $user->cityName();
        $currentCityId = $user->city_id;

        $effectiveCity = $resolvedCity;
        if (! $cityExplicitlyProvided && ! $cityExplicitlyCleared) {
            $effectiveCity = $this->resolveCity($currentCityId, $currentCityName);
        }

        $cityChanged = false;
        if ($cityExplicitlyCleared && ($currentCityId !== null || $currentCityName !== null)) {
            $cityChanged = true;
        } elseif ($cityExplicitlyProvided) {
            if ($resolvedCity && $currentCityId !== null) {
                $cityChanged = (int) $resolvedCity->id !== (int) $currentCityId;
            } else {
                $newCityLabel = $resolvedCity ? $resolvedCity->name : $cityNameInput;
                $cityChanged = $currentCityName === null
                    || mb_strtolower((string) $newCityLabel, 'UTF-8') !== mb_strtolower($currentCityName, 'UTF-8');
            }
        }

        // 3. Résolution et preuve géographique du quartier
        $hasNeighbourhoodKey = array_key_exists('neighbourhood', $input)
            || array_key_exists('neighbourhoodId', $input)
            || array_key_exists('neighbourhood_id', $input);

        $neighbourhoodIdInput = $input['neighbourhoodId'] ?? ($input['neighbourhood_id'] ?? null);
        $neighbourhoodNameInput = array_key_exists('neighbourhood', $input) && is_string($input['neighbourhood'])
            ? trim($input['neighbourhood'])
            : null;

        $neighbourhoodProvided = ($neighbourhoodIdInput !== null && $neighbourhoodIdInput !== '')
            || ($neighbourhoodNameInput !== null && $neighbourhoodNameInput !== '');

        if (! $hasNeighbourhoodKey) {
            // Si la ville change ou est effacée sans nouveau quartier, l'ancien quartier n'est plus cohérent.
            if ($cityChanged) {
                $updates['neighbourhood_id'] = null;
                $updates['neighbourhood'] = null;
                $updates['neighbourhood_verified_at'] = null;
            }

            return [
                'updates' => $updates,
                'neighbourhood_just_verified' => false,
            ];
        }

        if (! $neighbourhoodProvided) {
            // Effacement explicite du quartier
            $updates['neighbourhood_id'] = null;
            $updates['neighbourhood'] = null;
            $updates['neighbourhood_verified_at'] = null;

            return [
                'updates' => $updates,
                'neighbourhood_just_verified' => false,
            ];
        }

        // Une ville inconnue explicitement fournie ne peut pas valider un quartier d'une autre ville
        if ($cityExplicitlyProvided && $resolvedCity === null) {
            throw new NeighbourhoodNotVerifiedException('neighbourhood_unknown', $this->maxDistanceKm());
        }

        $latitude = isset($input['latitude']) && $input['latitude'] !== '' ? (float) $input['latitude'] : null;
        $longitude = isset($input['longitude']) && $input['longitude'] !== '' ? (float) $input['longitude'] : null;
        $accuracy = isset($input['accuracy']) && $input['accuracy'] !== '' ? (float) $input['accuracy'] : null;
        $coordinatesProvided = $latitude !== null && $longitude !== null;

        $neighbourhood = $this->resolveNeighbourhood(
            $neighbourhoodIdInput !== null && $neighbourhoodIdInput !== '' ? (int) $neighbourhoodIdInput : null,
            $neighbourhoodNameInput,
            $cityExplicitlyProvided ? $resolvedCity : $effectiveCity,
            $cityExplicitlyProvided
        );

        // Si le compte possède déjà ce même quartier vérifié et qu'aucune nouvelle position n'est fournie
        // (ex: formulaire d'édition de profil renvoyant l'état complet lors d'un changement de bio),
        // on conserve la vérification existante sans rejeter la requête.
        if (! $coordinatesProvided && ! $cityChanged && $this->isSameVerifiedNeighbourhood($user, $neighbourhood, $neighbourhoodNameInput)) {
            return [
                'updates' => $updates,
                'neighbourhood_just_verified' => false,
            ];
        }

        // Si aucune coordonnée GPS n'est fournie : enregistre le quartier comme non vérifié.
        // La preuve par la position GPS ne sert plus qu'à la pastille « Voisin ».
        if (! $coordinatesProvided) {
            $updates['neighbourhood_id'] = $neighbourhood?->id;
            $updates['neighbourhood'] = $neighbourhood ? $neighbourhood->name : $neighbourhoodNameInput;
            $updates['neighbourhood_verified_at'] = null;

            if ($neighbourhood && $neighbourhood->city) {
                $updates['city_id'] = $neighbourhood->city->id;
                $updates['city'] = $neighbourhood->city->name;
            } elseif ($cityExplicitlyProvided && $resolvedCity) {
                $updates['city_id'] = $resolvedCity->id;
                $updates['city'] = $resolvedCity->name;
            }

            return [
                'updates' => $updates,
                'neighbourhood_just_verified' => false,
            ];
        }

        if ($neighbourhood === null) {
            throw new NeighbourhoodNotVerifiedException('neighbourhood_unknown', $this->maxDistanceKm());
        }

        if ($cityExplicitlyProvided && $resolvedCity !== null && (int) $neighbourhood->city_id !== (int) $resolvedCity->id) {
            throw new NeighbourhoodNotVerifiedException('neighbourhood_unknown', $this->maxDistanceKm());
        }

        $verification = $this->verifyPositionForNeighbourhood($neighbourhood, $latitude, $longitude, $accuracy);

        if (! $verification['verified']) {
            throw new NeighbourhoodNotVerifiedException(
                $verification['reason'] ?? 'too_far',
                $this->maxDistanceKm()
            );
        }

        $updates['neighbourhood_id'] = $neighbourhood->id;
        $updates['neighbourhood'] = $neighbourhood->name;
        $updates['neighbourhood_verified_at'] = now();
        $neighbourhoodJustVerified = true;

        if ($neighbourhood->city) {
            $updates['city_id'] = $neighbourhood->city->id;
            $updates['city'] = $neighbourhood->city->name;
        }

        return [
            'updates' => $updates,
            'neighbourhood_just_verified' => $neighbourhoodJustVerified,
        ];
    }

    /**
     * Résout une ville par ID, nom (insensible à la casse) ou slug.
     */
    public function resolveCity(?int $cityId, ?string $cityName): ?City
    {
        if ($cityId !== null) {
            return City::find($cityId);
        }

        if ($cityName === null || trim($cityName) === '') {
            return null;
        }

        $trimmed = trim($cityName);
        $lower = mb_strtolower($trimmed, 'UTF-8');
        $slug = Str::slug($trimmed);

        return City::query()
            ->where(function ($query) use ($lower, $slug) {
                $query->whereRaw('LOWER(name) = ?', [$lower])
                    ->orWhere('slug', $slug);
            })
            ->orderByDesc('is_active')
            ->first();
    }

    /**
     * Résout un quartier actif par ID ou par nom/slug dans la ville cible.
     */
    public function resolveNeighbourhood(
        ?int $neighbourhoodId,
        ?string $neighbourhoodName,
        ?City $city,
        bool $cityExplicitlyProvided = false
    ): ?Neighbourhood {
        if ($neighbourhoodId !== null) {
            $neighbourhood = Neighbourhood::with('city')
                ->where('is_active', true)
                ->find($neighbourhoodId);

            if (! $neighbourhood) {
                return null;
            }

            if ($cityExplicitlyProvided && $city !== null && (int) $neighbourhood->city_id !== (int) $city->id) {
                return null;
            }

            return $neighbourhood;
        }

        if ($neighbourhoodName === null || trim($neighbourhoodName) === '') {
            return null;
        }

        $trimmed = trim($neighbourhoodName);
        $lower = mb_strtolower($trimmed, 'UTF-8');
        $slug = Str::slug($trimmed);

        $query = Neighbourhood::with('city')
            ->where('is_active', true);

        if ($city !== null) {
            $prefixedSlug = Str::slug($city->slug.'-'.$trimmed);

            return $query
                ->where('city_id', $city->id)
                ->where(function ($sub) use ($lower, $slug, $prefixedSlug) {
                    $sub->whereRaw('LOWER(name) = ?', [$lower])
                        ->orWhere('slug', $slug)
                        ->orWhere('slug', $prefixedSlug);
                })
                ->first();
        }

        // Sans ville connue, on n'accepte une correspondance par nom que si elle est unique
        $matches = $query
            ->where(function ($sub) use ($lower, $slug) {
                $sub->whereRaw('LOWER(name) = ?', [$lower])
                    ->orWhere('slug', $slug);
            })
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    protected function isSameVerifiedNeighbourhood(
        User $user,
        ?Neighbourhood $resolvedNeighbourhood,
        ?string $neighbourhoodNameInput
    ): bool {
        if (! $user->hasVerifiedNeighbourhood()) {
            return false;
        }

        if ($resolvedNeighbourhood !== null && $user->neighbourhood_id !== null) {
            return (int) $user->neighbourhood_id === (int) $resolvedNeighbourhood->id;
        }

        $currentName = $user->neighbourhoodName();
        $candidateName = $resolvedNeighbourhood?->name ?? $neighbourhoodNameInput;

        if ($currentName === null || $candidateName === null) {
            return false;
        }

        return mb_strtolower($currentName, 'UTF-8') === mb_strtolower(trim($candidateName), 'UTF-8');
    }
}
