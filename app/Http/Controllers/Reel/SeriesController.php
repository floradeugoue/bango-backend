<?php

namespace App\Http\Controllers\Reel;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reel\SeriesResource;
use App\Models\Reel\Series;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class SeriesController extends Controller
{
    /**
     * Liste des séries actives.
     *
     * GET /api/series
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Series::where('is_active', true)->with(['creator', 'page']);

        if ($request->filled('q')) {
            $search = mb_strtolower(trim((string) $request->query('q')));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(slug) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        $series = $query->latest()->paginate((int) $request->query('per_page', 20));

        return SeriesResource::collection($series);
    }

    /**
     * Affiche une série avec ses épisodes ordonnés.
     *
     * GET /api/series/{slug}
     */
    public function show(string $slug): JsonResponse
    {
        $series = Series::where('slug', $slug)
            ->orWhere('id', is_numeric($slug) ? (int) $slug : 0)
            ->with([
                'creator',
                'page',
                'reels' => fn ($query) => $query->orderBy('episode_number', 'asc')->orderBy('created_at', 'asc')->with(['user', 'page']),
            ])
            ->firstOrFail();

        return (new SeriesResource($series))->response();
    }

    /**
     * Crée une nouvelle série.
     *
     * POST /api/series
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:series,slug'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cover_url' => ['nullable', 'string', 'max:2048'],
            'page_id' => ['nullable', 'integer', 'exists:pages,id'],
        ]);

        $slug = ! empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        $originalSlug = $slug;
        $counter = 1;
        while (Series::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        $series = Series::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'cover_url' => $validated['cover_url'] ?? null,
            'user_id' => $request->user()?->id,
            'page_id' => $validated['page_id'] ?? null,
            'is_active' => true,
        ]);

        return (new SeriesResource($series))
            ->response()
            ->setStatusCode(201);
    }
}
