<?php

namespace App\Http\Controllers\Web;

use App\Enums\Listing\ListingStatus;
use App\Enums\Reel\ReelStatus;
use App\Enums\Square\PostAudience;
use App\Http\Controllers\Controller;
use App\Models\Listing\Listing;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicShareController extends Controller
{
    public const PLAY_STORE_URL = 'https://play.google.com/store/apps/details?id=com.bangoapp.app';

    /**
     * Aperçu web public d'une publication du Square (/p/{id}).
     */
    public function post(Request $request, string $id): View
    {
        /** @var Post|null $post */
        $post = Post::with(['user', 'media'])
            ->where('id', $id)
            ->first();

        $canonicalUrl = $request->fullUrl();
        $deepLink = "bango://post?id={$id}";

        // Vérification de la disponibilité et de la confidentialité
        $isAvailable = $post !== null
            && $post->deleted_at === null
            && ! $post->is_archived
            && $post->audience === PostAudience::Public
            && ! ($post->user?->is_private);

        if (! $isAvailable) {
            return view('pages.share', [
                'type' => 'post',
                'title' => 'Publication indisponible — BANGO',
                'description' => 'Cette publication n\'est pas disponible, est privée ou a été supprimée.',
                'image' => null,
                'url' => $canonicalUrl,
                'deepLink' => $deepLink,
                'playStoreUrl' => self::PLAY_STORE_URL,
                'isAvailable' => false,
                'item' => null,
            ]);
        }

        $author = $post->user;
        $authorName = $author?->display_name ?: ($author?->name ?: 'Membre BANGO');
        $authorHandle = $author?->handle ? '@'.$author->handle : null;

        $rawBody = trim((string) $post->body);
        $shortText = Str::limit($rawBody, 160, '...');

        $title = $authorName.' sur BANGO';
        if (! empty($rawBody)) {
            $title = Str::limit($rawBody, 60, '...').' — '.$authorName;
        }

        $description = ! empty($shortText)
            ? $shortText
            : "Découvrez la publication de {$authorName} sur le Square de BANGO.";

        $firstMedia = $post->media->first();
        $rawImage = $firstMedia?->poster_url ?: ($firstMedia?->media_url ?: ($author?->avatar_url ?: null));
        $image = $this->toAbsoluteUrl($rawImage);

        return view('pages.share', [
            'type' => 'post',
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'url' => $canonicalUrl,
            'deepLink' => $deepLink,
            'playStoreUrl' => self::PLAY_STORE_URL,
            'isAvailable' => true,
            'item' => [
                'id' => (string) $post->id,
                'author_name' => $authorName,
                'author_handle' => $authorHandle,
                'author_avatar' => $this->toAbsoluteUrl($author?->avatar_url),
                'body' => $post->body,
                'created_at' => $post->created_at?->diffForHumans() ?? 'Récemment',
                'likes_count' => (int) $post->likes_count,
                'comments_count' => (int) $post->comments_count,
                'media' => $post->media->map(fn ($m) => [
                    'url' => $this->toAbsoluteUrl($m->media_url),
                    'poster' => $this->toAbsoluteUrl($m->poster_url),
                    'type' => $m->media_type?->value ?? 'image',
                ])->all(),
            ],
        ]);
    }

    /**
     * Aperçu web public d'un Reel vidéo (/r/{id}).
     */
    public function reel(Request $request, string $id): View
    {
        /** @var Reel|null $reel */
        $reel = Reel::with(['user'])
            ->where('id', $id)
            ->first();

        $canonicalUrl = $request->fullUrl();
        $deepLink = "bango://reel?id={$id}";

        $isAvailable = $reel !== null
            && $reel->deleted_at === null
            && $reel->status === ReelStatus::Ready
            && ! ($reel->user?->is_private);

        if (! $isAvailable) {
            return view('pages.share', [
                'type' => 'reel',
                'title' => 'Reel indisponible — BANGO',
                'description' => 'Ce reel n\'est pas disponible, est privé ou a été supprimé.',
                'image' => null,
                'url' => $canonicalUrl,
                'deepLink' => $deepLink,
                'playStoreUrl' => self::PLAY_STORE_URL,
                'isAvailable' => false,
                'item' => null,
            ]);
        }

        $author = $reel->user;
        $authorName = $author?->display_name ?: ($author?->name ?: 'Créateur BANGO');
        $authorHandle = $author?->handle ? '@'.$author->handle : null;

        $caption = trim((string) $reel->caption);
        $shortCaption = Str::limit($caption, 160, '...');

        $title = 'Reel de '.$authorName.' sur BANGO';
        if (! empty($caption)) {
            $title = Str::limit($caption, 60, '...').' — '.$authorName;
        }

        $description = ! empty($shortCaption)
            ? $shortCaption
            : "Regardez la vidéo Reel de {$authorName} sur l'application BANGO.";

        $rawImage = $reel->thumbnail_url ?: ($author?->avatar_url ?: null);
        $image = $this->toAbsoluteUrl($rawImage);

        return view('pages.share', [
            'type' => 'reel',
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'url' => $canonicalUrl,
            'deepLink' => $deepLink,
            'playStoreUrl' => self::PLAY_STORE_URL,
            'isAvailable' => true,
            'item' => [
                'id' => (string) $reel->id,
                'author_name' => $authorName,
                'author_handle' => $authorHandle,
                'author_avatar' => $this->toAbsoluteUrl($author?->avatar_url),
                'caption' => $reel->caption,
                'video_url' => $this->toAbsoluteUrl($reel->video_url),
                'thumbnail_url' => $image,
                'duration' => (int) $reel->duration,
                'views_count' => (int) $reel->views_count,
                'likes_count' => (int) $reel->likes_count,
            ],
        ]);
    }

    /**
     * Aperçu web public d'une annonce immobilière (/l/{uuid}).
     */
    public function listing(Request $request, string $uuid): View
    {
        /** @var Listing|null $listing */
        $listing = Listing::with(['owner', 'photos', 'media'])
            ->where(function ($q) use ($uuid) {
                $q->where('id', $uuid)
                    ->orWhere('reference', $uuid);
            })
            ->first();

        $canonicalUrl = $request->fullUrl();
        $deepLink = "bango://listing?id={$uuid}";

        $isAvailable = $listing !== null
            && $listing->deleted_at === null
            && $listing->status === ListingStatus::Published;

        if (! $isAvailable) {
            return view('pages.share', [
                'type' => 'listing',
                'title' => 'Annonce indisponible — BANGO',
                'description' => 'Cette annonce n\'est plus disponible ou a été retirée sur BANGO.',
                'image' => null,
                'url' => $canonicalUrl,
                'deepLink' => $deepLink,
                'playStoreUrl' => self::PLAY_STORE_URL,
                'isAvailable' => false,
                'item' => null,
            ]);
        }

        $currency = $listing->currency ?: 'FCFA';
        $priceFormatted = number_format((float) $listing->price, 0, ',', ' ').' '.$currency;
        $location = trim(($listing->neighbourhood ? $listing->neighbourhood.', ' : '').($listing->city ?? ''));

        $title = $listing->title.' ('.$priceFormatted.') — BANGO';
        $descRaw = trim((string) $listing->description);
        $shortDesc = Str::limit($descRaw, 140, '...');

        $description = $priceFormatted.(! empty($location) ? ' à '.$location : '').(! empty($shortDesc) ? ' — '.$shortDesc : '');

        $firstPhoto = $listing->photos->first()?->url ?? $listing->media->first()?->url;
        $image = $this->toAbsoluteUrl($firstPhoto);

        return view('pages.share', [
            'type' => 'listing',
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'url' => $canonicalUrl,
            'deepLink' => $deepLink,
            'playStoreUrl' => self::PLAY_STORE_URL,
            'isAvailable' => true,
            'item' => [
                'id' => (string) $listing->id,
                'title' => $listing->title,
                'price_formatted' => $priceFormatted,
                'location' => $location,
                'bedrooms' => $listing->bedrooms,
                'showers' => $listing->showers,
                'area' => $listing->area,
                'description' => $listing->description,
                'image' => $image,
                'photos' => $listing->photos->take(4)->map(fn ($p) => $this->toAbsoluteUrl($p->url))->all(),
            ],
        ]);
    }

    /**
     * Aperçu web public d'un profil utilisateur (/@{handle} et /u/{handle}).
     */
    public function profile(Request $request, string $handle): View
    {
        $cleanHandle = ltrim($handle, '@');

        /** @var User|null $user */
        $user = User::where('handle', $cleanHandle)->first();

        $canonicalUrl = $request->fullUrl();
        $deepLink = "bango://u/{$cleanHandle}";

        $isAvailable = $user !== null
            && $user->deleted_at === null
            && $user->scheduled_for_deletion_at === null
            && ! $user->is_private;

        if (! $isAvailable) {
            return view('pages.share', [
                'type' => 'profile',
                'title' => 'Profil BANGO',
                'description' => 'Ce profil est privé ou indisponible sur BANGO.',
                'image' => null,
                'url' => $canonicalUrl,
                'deepLink' => $deepLink,
                'playStoreUrl' => self::PLAY_STORE_URL,
                'isAvailable' => false,
                'item' => null,
            ]);
        }

        $displayName = $user->display_name ?: ($user->name ?: '@'.$user->handle);
        $title = "{$displayName} (@{$user->handle}) sur BANGO";

        $bio = trim((string) $user->bio);
        $description = ! empty($bio)
            ? Str::limit($bio, 150, '...')
            : "Découvrez le profil de {$displayName} sur BANGO, l'application communautaire et immobilière.";

        $image = $this->toAbsoluteUrl($user->avatar_url);

        return view('pages.share', [
            'type' => 'profile',
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'url' => $canonicalUrl,
            'deepLink' => $deepLink,
            'playStoreUrl' => self::PLAY_STORE_URL,
            'isAvailable' => true,
            'item' => [
                'id' => (string) $user->id,
                'name' => $displayName,
                'handle' => '@'.$user->handle,
                'avatar' => $image,
                'bio' => $user->bio,
                'city' => $user->city,
                'badge' => $user->badge,
                'is_verified' => (bool) $user->is_verified,
            ],
        ]);
    }

    /**
     * Fichier officiel Android Digital Asset Links (App Links).
     * GET /.well-known/assetlinks.json
     */
    public function assetLinks(): JsonResponse
    {
        $fingerprintsEnv = env('ANDROID_APP_FINGERPRINTS');
        $fingerprints = ! empty($fingerprintsEnv)
            ? array_filter(array_map('trim', explode(',', $fingerprintsEnv)))
            : [
                'FA:C6:17:45:DC:09:03:78:6F:B9:ED:E6:2A:96:2B:39:9F:73:48:F0:BB:6F:89:9B:83:32:66:75:91:03:3B:9C',
            ];

        $packageName = env('ANDROID_APP_PACKAGE', 'com.bangoapp.app');

        $data = [
            [
                'relation' => [
                    'delegate_permission/common.handle_all_urls',
                ],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => $packageName,
                    'sha256_cert_fingerprints' => array_values($fingerprints),
                ],
            ],
        ];

        return response()->json($data)
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'max-age=86400, public');
    }

    /**
     * Fichier officiel Apple App Site Association (iOS Universal Links).
     * GET /.well-known/apple-app-site-association
     */
    public function appleAppSiteAssociation(): JsonResponse
    {
        $appId = env('IOS_APP_ID', 'TEAMID.com.bangoapp.app');

        $data = [
            'applinks' => [
                'apps' => [],
                'details' => [
                    [
                        'appID' => $appId,
                        'paths' => [
                            '/p/*',
                            '/r/*',
                            '/l/*',
                            '/@*',
                            '/u/*',
                        ],
                        'components' => [
                            ['/' => '/p/*'],
                            ['/' => '/r/*'],
                            ['/' => '/l/*'],
                            ['/' => '/@*'],
                            ['/' => '/u/*'],
                        ],
                    ],
                ],
            ],
        ];

        return response()->json($data)
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'max-age=86400, public');
    }

    /**
     * Convertit une URL relative en URL absolue avec scheme.
     */
    protected function toAbsoluteUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return url($url);
    }
}
