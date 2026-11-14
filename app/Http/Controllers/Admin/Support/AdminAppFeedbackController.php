<?php

namespace App\Http\Controllers\Admin\Support;

use App\Http\Controllers\Controller;
use App\Models\Support\AppFeedback;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAppFeedbackController extends Controller
{
    /**
     * Liste paginée des avis sur l'application avec compteurs par humeur et filtres.
     *
     * GET /api/admin/app-feedback
     */
    public function index(Request $request): JsonResponse
    {
        $query = AppFeedback::query();

        // Filtre recherche textuelle
        $search = trim((string) ($request->query('q') ?? $request->query('search')));
        if ($search !== '') {
            $isPgsql = $query->getConnection()->getDriverName() === 'pgsql';
            $like = $isPgsql ? 'ilike' : 'like';

            $query->where(function (Builder $q) use ($search, $like) {
                $q->where('comment', $like, "%{$search}%")
                    ->orWhere('platform', $like, "%{$search}%")
                    ->orWhere('app_version', $like, "%{$search}%")
                    ->orWhereHas('user', function (Builder $userQuery) use ($search, $like) {
                        $userQuery->where('name', $like, "%{$search}%")
                            ->orWhere('display_name', $like, "%{$search}%")
                            ->orWhere('handle', $like, "%{$search}%")
                            ->orWhere('email', $like, "%{$search}%")
                            ->orWhere('phone', $like, "%{$search}%");
                    });
            });
        }

        // Filtre plateforme
        if ($platform = $request->query('platform')) {
            $query->where('platform', $platform);
        }

        // Filtre version
        if ($version = ($request->query('version') ?? $request->query('app_version'))) {
            $query->where('app_version', $version);
        }

        // Filtre compte / utilisateur
        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        // Filtre dates
        if ($dateFrom = ($request->query('from') ?? $request->query('start_date'))) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        if ($dateTo = ($request->query('to') ?? $request->query('end_date'))) {
            $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
        }

        // Compteurs par humeur calculés sur la requête filtrée (sans le filtre de mood spécifique)
        $moodCountsQuery = (clone $query)
            ->reorder()
            ->selectRaw('mood, count(*) as count')
            ->groupBy('mood')
            ->pluck('count', 'mood')
            ->toArray();

        $totalAll = array_sum($moodCountsQuery);
        $counts = [
            'all' => $totalAll,
            'great' => (int) ($moodCountsQuery['great'] ?? 0),
            'fine' => (int) ($moodCountsQuery['fine'] ?? 0),
            'poor' => (int) ($moodCountsQuery['poor'] ?? 0),
        ];

        // Filtre par humeur (mood)
        if ($mood = $request->query('mood')) {
            if ($mood !== 'all') {
                $query->where('mood', $mood);
            }
        }

        // Eager loading
        $query->with([
            'user:id,name,display_name,handle,email,phone,avatar_url,badge',
            'page:id,name,handle,logo_url',
        ]);

        // Tri
        $sort = $request->query('sort', 'created_at');
        $order = strtolower((string) $request->query('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'mood', 'platform', 'app_version'];

        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $order);
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'items' => $paginator->items(),
            'counts' => $counts,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'counts' => $counts,
            ],
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'pageCount' => $paginator->lastPage(),
        ]);
    }
}
