<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Resources\Square\PostResource;
use App\Services\Square\PostFeedService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostFeedController extends Controller
{
    /**
     * Retourne le fil Square paginé (CR-11 et flux général).
     */
    public function index(Request $request, PostFeedService $service): AnonymousResourceCollection
    {
        $tab = (string) $request->query('tab', 'discover');
        $user = $request->user();

        $feed = $service->getFeed($user, [
            'tab' => $tab,
            'author_id' => $request->query('author_id') ? (int) $request->query('author_id') : null,
            'archived' => $request->boolean('archived'),
            'per_page' => $request->query('per_page') ? (int) $request->query('per_page') : 15,
        ]);

        $collection = PostResource::collection($feed);

        $activePage = $request->attributes->get('active_page');
        if ($tab === 'neighbourhood' && $user && ! $activePage && ! $request->query('author_id')) {
            $isVerified = $user->hasVerifiedNeighbourhood();
            $declaredNeighbourhood = $user->neighbourhoodName();
            $declaredCity = $user->cityName();
            $locale = $user->locale ?? 'fr';

            $message = null;
            if (! $isVerified) {
                $bodyKey = $declaredNeighbourhood
                    ? 'notifications.neighbourhood_confirmation_required.body_with_place'
                    : 'notifications.neighbourhood_confirmation_required.body';
                $message = trans($bodyKey, [
                    'neighbourhood' => $declaredNeighbourhood ?? '',
                    'city' => $declaredCity ?? '',
                ], $locale);
            }

            $collection->additional([
                'neighbourhood_verification' => [
                    'required' => ! $isVerified,
                    'code' => $isVerified ? null : 'NEIGHBOURHOOD_CONFIRMATION_REQUIRED',
                    'has_declared_neighbourhood' => $declaredNeighbourhood !== null,
                    'city' => $declaredCity,
                    'neighbourhood' => $declaredNeighbourhood,
                    'message' => $message,
                ],
            ]);
        }

        return $collection;
    }
}
