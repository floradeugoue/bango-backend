<?php

namespace App\Http\Controllers\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\PageSearchRequest;
use App\Http\Resources\Page\PageResource;
use App\Models\Page\Page;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PageSearchController extends Controller
{
    /**
     * Recherche publique de Pages Professionnelles avec filtres avancés (RC).
     */
    public function index(PageSearchRequest $request): AnonymousResourceCollection
    {
        $query = Page::query()->public();

        $searchTerm = $request->input('q') ?? $request->input('query');
        if (! empty($searchTerm)) {
            $term = trim($searchTerm);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('handle', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        if ($request->filled('sector')) {
            $query->where('sector', $request->input('sector'));
        }

        if ($request->filled('city')) {
            $query->where('city', $request->input('city'));
        }

        if ($request->filled('neighbourhood')) {
            $query->where('neighbourhood', $request->input('neighbourhood'));
        }

        if ($request->boolean('certified')) {
            $query->where('certification_status', PageCertificationStatus::Approved->value);
        }

        $pages = $query->with(['owner', 'activeMembers.user'])
            ->orderByRaw("CASE WHEN certification_status = 'approved' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return PageResource::collection($pages);
    }
}
