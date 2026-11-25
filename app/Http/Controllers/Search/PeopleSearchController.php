<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\Search\PeopleSearchResultResource;
use App\Http\Resources\Search\PersonSuggestionResource;
use App\Models\User;
use App\Services\Profile\ContactMatchingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
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
     * Suggestions de personnes inspirées du modèle TikTok (SQ-01, AU-19).
     *
     * Sources mélangées dans cet ordre strict :
     * 1. Amis d'amis (suivis par les personnes que je suis, sans limite de ville, classés par nombre de liens en commun)
     * 2. Contacts du téléphone (empreintes ou numéros déjà envoyés et rapprochés)
     * 3. Mon quartier
     * 4. Ma ville
     *
     * Exclusions strictes :
     * - Moi-même
     * - Comptes déjà suivis
     * - Blocages mutuels (bloqués par moi ou me bloquant)
     * - Comptes inactifs / suspendus / supprimés
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

        // Requête de base avec exclusions mutuelles strictes
        $baseUserQuery = function () use ($authUserId) {
            return User::query()
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->whereNull('suspended_until')
                        ->orWhere('suspended_until', '<=', now());
                })
                ->where('id', '!=', $authUserId)
                ->whereNotIn('id', function ($q) use ($authUserId) {
                    $q->select('following_id')->from('follows')->where('follower_id', $authUserId);
                })
                ->whereNotIn('id', function ($q) use ($authUserId) {
                    $q->select('blocked_id')->from('user_blocks')->where('blocker_id', $authUserId);
                })
                ->whereNotIn('id', function ($q) use ($authUserId) {
                    $q->select('blocker_id')->from('user_blocks')->where('blocked_id', $authUserId);
                });
        };

        $selectedUsers = collect();
        $selectedIds = collect();

        // 1. Source « amis d'amis » : comptes suivis par les personnes que je suis, sans limite de ville.
        // Classés par nombre de liens en commun décroissant.
        $fofDataQuery = DB::table('follows as f_target')
            ->whereIn('f_target.follower_id', function ($sub) use ($authUserId) {
                $sub->select('following_id')->from('follows')->where('follower_id', $authUserId);
            })
            ->where('f_target.following_id', '!=', $authUserId)
            ->whereNotIn('f_target.following_id', function ($q) use ($authUserId) {
                $q->select('following_id')->from('follows')->where('follower_id', $authUserId);
            })
            ->whereNotIn('f_target.following_id', function ($q) use ($authUserId) {
                $q->select('blocked_id')->from('user_blocks')->where('blocker_id', $authUserId);
            })
            ->whereNotIn('f_target.following_id', function ($q) use ($authUserId) {
                $q->select('blocker_id')->from('user_blocks')->where('blocked_id', $authUserId);
            })
            ->select('f_target.following_id as user_id', DB::raw('COUNT(DISTINCT f_target.follower_id) as common_links_count'))
            ->groupBy('f_target.following_id')
            ->orderByDesc('common_links_count')
            ->limit($limit);

        $fofResults = $fofDataQuery->get();
        if ($fofResults->isNotEmpty()) {
            $fofUserIds = $fofResults->pluck('user_id')->all();
            $fofUsers = $baseUserQuery()
                ->with(['city', 'neighbourhood'])
                ->whereIn('id', $fofUserIds)
                ->when($scope === 'neighbourhood' && ($myNeighbourhood || $myNeighbourhoodId), function ($q) use ($myNeighbourhood, $myNeighbourhoodId) {
                    $q->where(function ($sub) use ($myNeighbourhood, $myNeighbourhoodId) {
                        if ($myNeighbourhood) {
                            $sub->where('neighbourhood', $myNeighbourhood);
                        }
                        if ($myNeighbourhoodId) {
                            $sub->orWhere('neighbourhood_id', $myNeighbourhoodId);
                        }
                    });
                })
                ->get()
                ->sortBy(function ($user) use ($fofUserIds) {
                    return array_search($user->id, $fofUserIds, true);
                })
                ->values();

            foreach ($fofUsers as $user) {
                if ($selectedUsers->count() >= $limit) {
                    break;
                }
                $user->reason = 'friends_of_friends';
                $selectedUsers->push($user);
                $selectedIds->push($user->id);
            }
        }

        // 2. Source « contacts du téléphone » (empreintes déjà envoyées)
        if ($selectedUsers->count() < $limit) {
            $cachedContactIds = (array) Cache::get('user_contact_matches:'.$authUserId, []);

            // Prendre aussi en compte d'éventuelles empreintes passées directement dans la requête
            $inputFingerprints = $request->input('fingerprints', []);
            $inputPhones = $request->input('phones', $request->input('numbers', []));
            $inputs = array_merge((array) $inputFingerprints, (array) $inputPhones);
            if (! empty($inputs)) {
                try {
                    $matchResult = app(ContactMatchingService::class)->match($authUser, $inputs);
                    $newMatchedIds = collect($matchResult['suggestions'])
                        ->pluck('id')
                        ->map(fn ($id) => (int) $id)
                        ->filter(fn ($id) => $id > 0)
                        ->all();
                    $cachedContactIds = array_values(array_unique(array_merge($cachedContactIds, $newMatchedIds)));
                    Cache::put('user_contact_matches:'.$authUserId, $cachedContactIds, now()->addDays(30));
                } catch (\Throwable) {
                    // Ignorer les erreurs d'analyse contacts
                }
            }

            $contactUserIds = array_values(array_diff($cachedContactIds, $selectedIds->all()));
            if (! empty($contactUserIds)) {
                $needed = $limit - $selectedUsers->count();
                $contactUsers = $baseUserQuery()
                    ->with(['city', 'neighbourhood'])
                    ->whereIn('id', $contactUserIds)
                    ->when($scope === 'neighbourhood' && ($myNeighbourhood || $myNeighbourhoodId), function ($q) use ($myNeighbourhood, $myNeighbourhoodId) {
                        $q->where(function ($sub) use ($myNeighbourhood, $myNeighbourhoodId) {
                            if ($myNeighbourhood) {
                                $sub->where('neighbourhood', $myNeighbourhood);
                            }
                            if ($myNeighbourhoodId) {
                                $sub->orWhere('neighbourhood_id', $myNeighbourhoodId);
                            }
                        });
                    })
                    ->orderByDesc('is_verified')
                    ->orderBy('id', 'asc')
                    ->limit($needed)
                    ->get();

                foreach ($contactUsers as $user) {
                    if ($selectedUsers->count() >= $limit) {
                        break;
                    }
                    $user->reason = 'contact';
                    $selectedUsers->push($user);
                    $selectedIds->push($user->id);
                }
            }
        }

        // 3. Source « mon quartier »
        if ($selectedUsers->count() < $limit && ($myNeighbourhood || $myNeighbourhoodId)) {
            $needed = $limit - $selectedUsers->count();
            $neighbourhoodUsers = $baseUserQuery()
                ->with(['city', 'neighbourhood'])
                ->when($selectedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $selectedIds->all()))
                ->where(function ($q) use ($myNeighbourhood, $myNeighbourhoodId) {
                    if ($myNeighbourhood) {
                        $q->where('neighbourhood', $myNeighbourhood);
                    }
                    if ($myNeighbourhoodId) {
                        $q->orWhere('neighbourhood_id', $myNeighbourhoodId);
                    }
                })
                ->orderByDesc('is_verified')
                ->orderBy('id', 'asc')
                ->limit($needed)
                ->get();

            foreach ($neighbourhoodUsers as $user) {
                if ($selectedUsers->count() >= $limit) {
                    break;
                }
                $user->reason = 'neighbourhood';
                $selectedUsers->push($user);
                $selectedIds->push($user->id);
            }
        }

        // 4. Source « ma ville »
        if ($selectedUsers->count() < $limit && ($myCity || $myCityId) && $scope !== 'neighbourhood') {
            $needed = $limit - $selectedUsers->count();
            $cityUsers = $baseUserQuery()
                ->with(['city', 'neighbourhood'])
                ->when($selectedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $selectedIds->all()))
                ->where(function ($q) use ($myCity, $myCityId) {
                    if ($myCity) {
                        $q->where('city', $myCity);
                    }
                    if ($myCityId) {
                        $q->orWhere('city_id', $myCityId);
                    }
                })
                ->orderByDesc('is_verified')
                ->orderBy('id', 'asc')
                ->limit($needed)
                ->get();

            foreach ($cityUsers as $user) {
                if ($selectedUsers->count() >= $limit) {
                    break;
                }
                $user->reason = 'city';
                $selectedUsers->push($user);
                $selectedIds->push($user->id);
            }
        }

        // 5. Fallback utilisateurs actifs si ville ou quartier non renseignés
        if ($selectedUsers->count() < $limit && $scope !== 'neighbourhood') {
            $needed = $limit - $selectedUsers->count();
            $fallbackUsers = $baseUserQuery()
                ->with(['city', 'neighbourhood'])
                ->when($selectedIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $selectedIds->all()))
                ->orderByDesc('is_verified')
                ->orderBy('id', 'asc')
                ->limit($needed)
                ->get();

            foreach ($fallbackUsers as $user) {
                if ($selectedUsers->count() >= $limit) {
                    break;
                }
                $user->reason = 'city';
                $selectedUsers->push($user);
                $selectedIds->push($user->id);
            }
        }

        // Calcul des abonnés et liens en commun (mutual_followers_count et mutual_names)
        $candidateIds = $selectedUsers->pluck('id')->all();
        if (! empty($candidateIds)) {
            $mutualRows = DB::table('follows as f_target')
                ->join('users as u', 'u.id', '=', 'f_target.follower_id')
                ->whereIn('f_target.following_id', $candidateIds)
                ->where(function ($w) use ($authUserId) {
                    $w->whereIn('u.id', function ($sub) use ($authUserId) {
                        $sub->select('following_id')->from('follows')->where('follower_id', $authUserId);
                    })->orWhereIn('u.id', function ($sub) use ($authUserId) {
                        $sub->select('follower_id')->from('follows')->where('following_id', $authUserId);
                    });
                })
                ->select('f_target.following_id as candidate_id', 'u.id as user_id', 'u.display_name', 'u.name')
                ->distinct()
                ->get();

            $mutualGrouped = $mutualRows->groupBy('candidate_id');

            foreach ($selectedUsers as $user) {
                $rows = $mutualGrouped->get($user->id, collect());
                $user->mutual_followers_count = $rows->count();

                $names = [];
                foreach ($rows as $row) {
                    $rawName = trim((string) ($row->display_name ?: $row->name));
                    if ($rawName !== '') {
                        $parts = preg_split('/\s+/', $rawName);
                        $firstName = $parts[0] ?? $rawName;
                        if ($firstName !== '' && ! in_array($firstName, $names, true)) {
                            $names[] = $firstName;
                        }
                    }
                    if (count($names) >= 2) {
                        break;
                    }
                }

                $user->mutual_names = $names;
            }
        }

        return PersonSuggestionResource::collection($selectedUsers);
    }
}
