<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Token;

class SessionService
{
    public const MAX_ACTIVE_DEVICES = 5;

    /**
     * Résout les métadonnées géographiques (IP, ville, pays, libellé du lieu) à partir d'une requête et d'un utilisateur.
     *
     * @return array{ip: string, city: ?string, country: ?string, place: string}
     */
    public function resolveLocation(?string $ip = null, ?User $user = null, ?Request $request = null): array
    {
        $resolvedIp = $ip ?: ($request?->ip() ?: '127.0.0.1');

        // 1. Priorité aux entêtes explicites transmises par l'application mobile ou le reverse proxy
        $city = $request?->header('X-Device-City')
            ?: ($request?->header('CF-IPCity')
            ?: ($request?->input('device_city') ?: null));

        $country = $request?->header('X-Device-Country')
            ?: ($request?->header('CF-IPCountry')
            ?: ($request?->input('device_country') ?: null));

        // 2. Si non fournies par entête et si l'IP est publique, résolution GeoIP avec mise en cache
        if ((! $city || ! $country) && $this->isPublicIp($resolvedIp)) {
            $geoData = Cache::remember('ip.geo.'.$resolvedIp, now()->addDays(30), function () use ($resolvedIp) {
                try {
                    $response = Http::timeout(1)->get("http://ip-api.com/json/{$resolvedIp}?fields=status,country,city");
                    if ($response->successful() && ($response->json('status') === 'success')) {
                        return [
                            'city' => $response->json('city'),
                            'country' => $response->json('country'),
                        ];
                    }
                } catch (\Throwable) {
                    // Ignorer les erreurs réseau éventuelles
                }

                return null;
            });

            if ($geoData) {
                $city = $city ?: ($geoData['city'] ?? null);
                $country = $country ?: ($geoData['country'] ?? null);
            }
        }

        // 3. Repli contextuel sur le profil de l'utilisateur ou la ville par défaut
        $city = $city ?: ($user?->city ?: 'Douala');
        $country = $country ?: ($user?->country?->name ?: 'Cameroun');

        $place = $this->formatPlace($city, $country);

        return [
            'ip' => $resolvedIp,
            'city' => $city,
            'country' => $country,
            'place' => $place,
        ];
    }

    /**
     * Révoque les sessions actives les moins récemment utilisées afin de ne pas dépasser le plafond autorisé.
     * Pour une limite globale de 5 appareils, on conserve au maximum ($maxDevices - 1) avant d'émettre le nouveau jeton.
     */
    public function pruneActiveSessions(User $user, int $maxAllowedRemaining = 4): void
    {
        $activeTokens = $user->tokens()
            ->where('revoked', false)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('updated_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($activeTokens->count() > $maxAllowedRemaining) {
            $tokensToRevoke = $activeTokens->slice($maxAllowedRemaining);

            foreach ($tokensToRevoke as $token) {
                $token->delete();
            }
        }
    }

    /**
     * Gère la création complète d'un jeton de session pour un utilisateur :
     * - Révocation des anciens jetons du même appareil
     * - Maintien du plafond de 5 appareils au plus (révocation du moins récent si nécessaire)
     * - Enregistrement des métadonnées (IP, ville, pays)
     *
     * @return array{token: string, token_id: string}
     */
    public function issueSessionToken(
        User $user,
        string $tokenName,
        ?Request $request = null,
        ?string $deviceId = null,
        ?string $deviceName = null
    ): array {
        // 1. Révoquer les anciens jetons de ce même appareil
        if ($deviceId) {
            $user->tokens()->where(function ($query) use ($deviceId, $deviceName) {
                $query->where('name', 'like', "%::{$deviceId}")
                    ->orWhere('name', $deviceName);
            })->delete();
        } elseif ($deviceName) {
            $user->tokens()->where('name', $deviceName)->delete();
        }

        // 2. Limite à 5 appareils au plus : révoquer les moins récemment utilisés avant d'en créer un nouveau
        $this->pruneActiveSessions($user, self::MAX_ACTIVE_DEVICES - 1);

        // 3. Création du jeton Passport
        $tokenResult = $user->createToken($tokenName);
        $tokenModel = $tokenResult->token;

        // 4. Résolution et enregistrement de l'IP et du lieu
        $location = $this->resolveLocation($request?->ip(), $user, $request);

        if ($tokenModel instanceof Token) {
            $tokenModel->forceFill([
                'ip_address' => $location['ip'],
                'city' => $location['city'],
                'country' => $location['country'],
            ])->save();
        }

        // Cache de sécurité pour persistance supplémentaire
        Cache::put('session.meta.'.$tokenModel->id, [
            'ip' => $location['ip'],
            'city' => $location['city'],
            'country' => $location['country'],
            'place' => $location['place'],
            'created_at' => $tokenModel->created_at?->toIso8601String(),
        ], now()->addDays(90));

        return [
            'token' => $tokenResult->accessToken,
            'token_id' => (string) $tokenModel->id,
        ];
    }

    /**
     * Formate la liste des sessions d'un utilisateur avec toutes les métadonnées et le statut 'unusual'.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getFormattedSessions(User $user, ?string $currentTokenId = null, ?Request $request = null): Collection
    {
        // 1. Collecte des lieux habituels du compte (ville et pays du profil + sessions acquittées)
        $habitualCities = array_filter([$user->city]);
        $habitualCountries = array_filter([$user->country?->name]);

        // 2. Récupération des jetons actifs
        $activeTokens = $user->tokens
            ->filter(fn ($token) => ! $token->revoked && ($token->expires_at === null || ! $token->expires_at->isPast()))
            ->values();

        // Si le profil n'a aucun lieu renseigné, utiliser la ville/pays de la session la plus ancienne ou de la session courante
        if (empty($habitualCities) || empty($habitualCountries)) {
            $baselineToken = $activeTokens->firstWhere('id', $currentTokenId) ?: $activeTokens->last();
            if ($baselineToken) {
                if (empty($habitualCities) && ! empty($baselineToken->city)) {
                    $habitualCities[] = $baselineToken->city;
                }
                if (empty($habitualCountries) && ! empty($baselineToken->country)) {
                    $habitualCountries[] = $baselineToken->country;
                }
            }

            if (empty($habitualCities)) {
                $habitualCities[] = 'Douala';
            }
            if (empty($habitualCountries)) {
                $habitualCountries[] = 'Cameroun';
            }
        }

        // Ajouter les villes/pays des sessions préalablement acquittées aux lieux habituels
        foreach ($activeTokens as $token) {
            if (Cache::get('session.ack.'.$token->id, false)) {
                if (! empty($token->city)) {
                    $habitualCities[] = $token->city;
                }
                if (! empty($token->country)) {
                    $habitualCountries[] = $token->country;
                }
            }
        }

        $habitualCitiesLower = array_map(fn ($c) => strtolower(trim($c)), array_unique($habitualCities));
        $habitualCountriesLower = array_map(fn ($c) => strtolower(trim($c)), array_unique($habitualCountries));

        return $activeTokens->map(function ($token) use ($currentTokenId, $user, $request, $habitualCitiesLower, $habitualCountriesLower) {
            $isAcknowledged = (bool) Cache::get('session.ack.'.$token->id, false);

            $cachedMeta = Cache::get('session.meta.'.$token->id, []);

            $ip = $token->ip_address
                ?: ($cachedMeta['ip'] ?? ($token->id === $currentTokenId ? $request?->ip() : null))
                ?: '127.0.0.1';

            $city = $token->city
                ?: ($cachedMeta['city'] ?? ($user->city ?: 'Douala'));

            $country = $token->country
                ?: ($cachedMeta['country'] ?? ($user->country?->name ?: 'Cameroun'));

            $place = $cachedMeta['place'] ?? $this->formatPlace($city, $country);

            $rawName = (! empty($token->name) && $token->name !== 'auth_token') ? $token->name : 'Application mobile';
            $deviceName = str_contains($rawName, '::') ? explode('::', $rawName)[0] : $rawName;

            // Détection "unusual" : vrai SEULEMENT si la ville ou le pays diffère de ceux des connexions habituelles
            $isUnusual = false;
            if (! $isAcknowledged) {
                $cityMatches = empty($city) || in_array(strtolower(trim($city)), $habitualCitiesLower, true);
                $countryMatches = empty($country) || in_array(strtolower(trim($country)), $habitualCountriesLower, true);

                if (! $cityMatches || ! $countryMatches) {
                    $isUnusual = true;
                }
            }

            return [
                'id' => (string) $token->id,
                'device' => $deviceName,
                'current' => $currentTokenId !== null && $token->id === $currentTokenId,
                'ip' => $ip,
                'city' => $city,
                'country' => $country,
                'place' => $place,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->updated_at?->toIso8601String(),
                'unusual' => $isUnusual,
                'acknowledged' => $isAcknowledged,
            ];
        });
    }

    /**
     * Formate le libellé de localisation à partir de la ville et du pays.
     */
    public function formatPlace(?string $city, ?string $country): string
    {
        if ($city && $country) {
            return ($city === $country) ? $city : "{$city}, {$country}";
        }

        return $city ?: ($country ?: 'Douala, Cameroun');
    }

    /**
     * Vérifie si une adresse IP est une adresse publique valide.
     */
    protected function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
