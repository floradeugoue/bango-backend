<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\Search\PeopleSearchResultResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PeopleSearchController extends Controller
{
    /**
     * Recherche de profils pour tag de photo ou mention (CR-08).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = trim((string) $request->query('q', ''));
        $queryClean = mb_strtolower(ltrim($query, '@'), 'UTF-8');

        $users = User::query()
            ->when($queryClean !== '', function ($q) use ($queryClean) {
                $q->where(function ($sub) use ($queryClean) {
                    $sub->where(DB::raw('LOWER(handle)'), 'like', "%{$queryClean}%")
                        ->orWhere(DB::raw('LOWER(display_name)'), 'like', "%{$queryClean}%")
                        ->orWhere(DB::raw('LOWER(name)'), 'like', "%{$queryClean}%");
                });
            })
            ->where('status', 'active')
            ->orderBy('is_verified', 'desc')
            ->orderBy('id', 'asc')
            ->limit(20)
            ->get();

        return PeopleSearchResultResource::collection($users);
    }
}
