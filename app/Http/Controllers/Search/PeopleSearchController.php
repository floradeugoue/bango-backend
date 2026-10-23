<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\Search\PeopleSearchResultResource;
use App\Http\Resources\Search\PersonSuggestionResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PeopleSearchController extends Controller
{
    /**
     * Recherche de profils pour tag de photo ou mention (CR-08, RC-02).
     * Accepte désormais les filtres optionnels city et neighbourhood.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q', ''));
        $queryClean = mb_strtolower(ltrim($query, '@'), 'UTF-8');
        $city = $request->query('city');
        $neighbourhood = $request->query('neighbourhood');

        $usersQuery = User::query()
            ->when($queryClean !== '', function ($q) use ($queryClean) {
                $q->where(function ($sub) use ($queryClean) {
                    $sub->where(DB::raw('LOWER(handle)'), 'like', "%{$queryClean}%")
                        ->orWhere(DB::raw('LOWER(display_name)'), 'like', "%{$queryClean}%")
                        ->orWhere(DB::raw('LOWER(name)'), 'like', "%{$queryClean}%");
                });
            })
            ->when($city, function ($q) use ($city) {
                $q->where(function ($sub) use ($city) {
                    $sub->where('city', $city)
                        ->orWhereHas('city', fn ($cq) => $cq->where('name', $city)->orWhere('slug', Str::slug($city)));
                });
            })
            ->when($neighbourhood, function ($q) use ($neighbourhood) {
                $q->where(function ($sub) use ($neighbourhood) {
                    $sub->where('neighbourhood', $neighbourhood)
                        ->orWhereHas('neighbourhood', fn ($nq) => $nq->where('name', $neighbourhood)->orWhere('slug', Str::slug($neighbourhood)));
                });
            })
            ->where('status', 'active');

        // Exclure soi-même et les blocages mutuels si authentifié
        $authUser = $request->user('api') ?? $request->user();
        if ($authUser) {
            $authUserId = $authUser->id;
            $usersQuery->where('id', '!=', $authUserId)
                ->whereNotIn('id', function ($q) use ($authUserId) {
                    $q->select('blocked_id')->from('user_blocks')->where('blocker_id', $authUserId);
                })
                ->whereNotIn('id', function ($q) use ($authUserId) {
                    $q->select('blocker_id')->from('user_blocks')->where('blocked_id', $authUserId);
                });
        }

        $users = $usersQuery->orderBy('is_verified', 'desc')
            ->orderBy('id', 'asc')
            ->limit(20)
            ->get();

        return PeopleSearchResultResource::collection($users);
    }

    /**
     * Suggestions « voisins » (SQ-01 bloc « Près de toi », AU-19).
     *
     * GET /people/suggestions?scope=neighbourhood|city&limit=20
     * Comptes actifs, hors moi, hors bloqués et déjà suivis.
     * Même quartier d'abord, puis même ville.
     */
    public function suggestions(Request $request): AnonymousResourceCollection
    {
        $authUser = $request->user('api') ?? $request->user();
        if (! $authUser) {
            abort(401, 'Unauthenticated.');
        }

        $authUserId = $authUser->id;
        $scope = $request->query('scope', 'city');
        $limit = min(max($request->integer('limit', 20), 1), 50);

        $myCity = $authUser->getRawOriginal('city') ?: ($authUser->city?->name ?? null);
        $myCityId = $authUser->city_id;

        $myNeighbourhood = $authUser->getRawOriginal('neighbourhood') ?: ($authUser->neighbourhood?->name ?? null);
        $myNeighbourhoodId = $authUser->neighbourhood_id;

        $query = User::query()
            ->with(['city', 'neighbourhood'])
            ->where('status', 'active')
            ->where('id', '!=', $authUserId);

        // Exclure les comptes déjà suivis
        $query->whereNotIn('id', function ($q) use ($authUserId) {
            $q->select('following_id')
                ->from('follows')
                ->where('follower_id', $authUserId);
        });

        // Exclure les comptes bloqués (bloqués par moi)
        $query->whereNotIn('id', function ($q) use ($authUserId) {
            $q->select('blocked_id')
                ->from('user_blocks')
                ->where('blocker_id', $authUserId);
        });

        // Exclure les comptes qui me bloquent
        $query->whereNotIn('id', function ($q) use ($authUserId) {
            $q->select('blocker_id')
                ->from('user_blocks')
                ->where('blocked_id', $authUserId);
        });

        // Calcul des abonnés en commun (mutual_followers_count)
        $query->select('users.*');
        $query->selectSub(function ($q) use ($authUserId) {
            $q->selectRaw('COUNT(DISTINCT f1.follower_id)')
                ->from('follows as f1')
                ->whereColumn('f1.following_id', 'users.id')
                ->whereIn('f1.follower_id', function ($sub) use ($authUserId) {
                    $sub->select('f2.follower_id')
                        ->from('follows as f2')
                        ->where('f2.following_id', $authUserId);
                });
        }, 'mutual_followers_count');

        // Filtrage selon la portée demandée (scope)
        if ($scope === 'neighbourhood') {
            if ($myNeighbourhood || $myNeighbourhoodId) {
                $query->where(function ($q) use ($myNeighbourhood, $myNeighbourhoodId) {
                    if ($myNeighbourhood) {
                        $q->where('neighbourhood', $myNeighbourhood);
                    }
                    if ($myNeighbourhoodId) {
                        $q->orWhere('neighbourhood_id', $myNeighbourhoodId);
                    }
                });
            } else {
                $query->whereRaw('1 = 0');
            }
        } elseif ($myCity || $myCityId) {
            $query->where(function ($q) use ($myCity, $myCityId) {
                if ($myCity) {
                    $q->where('city', $myCity);
                }
                if ($myCityId) {
                    $q->orWhere('city_id', $myCityId);
                }
            });
        }

        // Tri : même quartier d'abord, puis même ville, puis abonnés en commun, puis vérifiés
        if ($myNeighbourhood || $myNeighbourhoodId) {
            $query->orderByRaw('CASE WHEN (neighbourhood = ? OR (neighbourhood_id IS NOT NULL AND neighbourhood_id = ?)) THEN 0 ELSE 1 END ASC', [
                $myNeighbourhood ?? '',
                $myNeighbourhoodId ?? 0,
            ]);
        }

        if ($myCity || $myCityId) {
            $query->orderByRaw('CASE WHEN (city = ? OR (city_id IS NOT NULL AND city_id = ?)) THEN 0 ELSE 1 END ASC', [
                $myCity ?? '',
                $myCityId ?? 0,
            ]);
        }

        $query->orderByDesc('mutual_followers_count')
            ->orderByDesc('is_verified')
            ->orderBy('id', 'asc');

        $users = $query->limit($limit)->get();

        return PersonSuggestionResource::collection($users);
    }
}
