<?php

namespace App\Services\Story;

use App\Enums\Message\MessageType;
use App\Enums\Story\StoryAudience;
use App\Enums\Story\StoryMediaType;
use App\Enums\Story\StoryReplyType;
use App\Enums\Story\StoryStatus;
use App\Enums\Story\StoryStickerType;
use App\Events\Story\StoryReplyCreated;
use App\Models\Reel\Sound;
use App\Models\Story\Story;
use App\Models\Story\StoryLike;
use App\Models\Story\StoryPollVote;
use App\Models\Story\StoryReply;
use App\Models\Story\StoryReport;
use App\Models\Story\StorySlide;
use App\Models\Story\StorySticker;
use App\Models\Story\StoryView;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use App\Services\Media\MediaStorageService;
use App\Services\Message\MessageService;
use App\Services\Message\MessageThreadService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class StoryService
{
    public function __construct(protected MediaStorageService $mediaStorageService) {}

    /**
     * Création et publication atomique d'une Story avec médias et stickers (CR3).
     *
     * @param  array{
     *     audience?: string,
     *     allow_replies?: bool,
     *     allow_archive?: bool,
     *     caption?: ?string,
     *     filter?: ?string,
     *     duration?: ?int,
     *     sound_id?: ?string,
     *     sound_start_at?: ?int,
     *     stickers?: array<array<string, mixed>>,
     *     slides?: array<array<string, mixed>>
     * }  $data
     * @param  array<string, UploadedFile|array<UploadedFile>>  $files
     */
    public function createStory(User $user, array $data, array $files): Story
    {
        $audienceValue = $data['audience'] ?? StoryAudience::Everyone->value;
        $audience = StoryAudience::tryFrom($audienceValue) ?? StoryAudience::Everyone;

        // Validation de la liste choisie si audience = chosen
        if ($audience === StoryAudience::Chosen && $user->closeFriends()->count() === 0) {
            throw ValidationException::withMessages([
                'audience' => ['Votre liste choisie est actuellement vide. Veuillez ajouter des proches avant de publier avec cette audience.'],
            ]);
        }

        // Fichiers physiques créés pour compensation en cas d'échec transactionnel
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($user, $data, $files, $audience, &$storedPaths) {
                $now = now();
                $expiresAt = (clone $now)->addHours(24);

                $story = Story::create([
                    'user_id' => $user->id,
                    'audience' => $audience,
                    'allow_replies' => (bool) ($data['allow_replies'] ?? true),
                    'allow_archive' => (bool) ($data['allow_archive'] ?? true),
                    'status' => StoryStatus::Active,
                    'published_at' => $now,
                    'expires_at' => $expiresAt,
                    'views_count' => 0,
                    'poll_votes_count' => 0,
                    'replies_count' => 0,
                    'metadata' => [
                        'filter' => $data['filter'] ?? null,
                        'source' => 'mobile_camera_or_gallery',
                    ],
                ]);

                // Résolution du son audio si référencé (RL / CR2 Sound catalogue reuse)
                $soundId = $data['sound_id'] ?? null;
                $soundMetadata = null;
                if ($soundId) {
                    $sound = Sound::find($soundId);
                    if (! $sound) {
                        throw ValidationException::withMessages([
                            'sound_id' => ['Le son sélectionné est introuvable dans le catalogue.'],
                        ]);
                    }
                    if ($sound->is_removed) {
                        throw ValidationException::withMessages([
                            'sound_id' => ['Ce son n\'est plus disponible dans le catalogue.'],
                        ]);
                    }
                    if ($sound->is_locked && $sound->user_id !== $user->id) {
                        throw ValidationException::withMessages([
                            'sound_id' => ['Ce son original a été verrouillé par son créateur et ne peut plus être utilisé.'],
                        ]);
                    }
                    $soundMetadata = [
                        'id' => $sound->id,
                        'title' => $sound->title,
                        'artist' => $sound->artist,
                        'audio_url' => $sound->audio_url,
                        'start_at' => (int) ($data['sound_start_at'] ?? 0),
                    ];
                }

                // Cas 1 : Diapositives multiples structurées
                if (isset($data['slides']) && is_array($data['slides']) && ! empty($data['slides'])) {
                    foreach ($data['slides'] as $index => $slideData) {
                        /** @var UploadedFile|null $slideFile */
                        $slideFile = $slideData['file'] ?? ($files['slides'][$index]['file'] ?? null);
                        if (! $slideFile instanceof UploadedFile) {
                            throw ValidationException::withMessages([
                                "slides.{$index}.file" => ["Fichier média manquant pour la diapositive #{$index}."],
                            ]);
                        }

                        $mediaInfo = $this->mediaStorageService->storeStoryMedia($slideFile, $user);
                        $storedPaths[] = $mediaInfo['path'];

                        $slideThumbFile = $slideData['thumbnail'] ?? ($files['slides'][$index]['thumbnail'] ?? null);
                        $slideThumbPath = $mediaInfo['path'];
                        $slideThumbUrl = $mediaInfo['url'];
                        if ($slideThumbFile instanceof UploadedFile) {
                            $thumbInfo = $this->mediaStorageService->storeStoryMedia($slideThumbFile, $user);
                            $storedPaths[] = $thumbInfo['path'];
                            $slideThumbPath = $thumbInfo['path'];
                            $slideThumbUrl = $thumbInfo['url'];
                        } elseif (! empty($slideData['thumbnail_url'])) {
                            $slideThumbPath = $slideData['thumbnail_url'];
                            $slideThumbUrl = $slideData['thumbnail_url'];
                        }

                        $slide = StorySlide::create([
                            'story_id' => $story->id,
                            'media_type' => $mediaInfo['type'] === 'video' ? StoryMediaType::Video : StoryMediaType::Image,
                            'media_path' => $mediaInfo['path'],
                            'media_url' => $mediaInfo['url'],
                            'thumbnail_path' => $slideThumbPath,
                            'thumbnail_url' => $slideThumbUrl,
                            'filter' => $slideData['filter'] ?? ($data['filter'] ?? null),
                            'caption' => $slideData['caption'] ?? null,
                            'duration' => $slideData['duration'] ?? ($mediaInfo['duration'] ?? ($mediaInfo['type'] === 'image' ? 5 : null)),
                            'order' => $index,
                            'sound_id' => $slideData['sound_id'] ?? $soundId,
                            'sound_metadata' => $slideData['sound_metadata'] ?? $soundMetadata,
                            'metadata' => [
                                'width' => $mediaInfo['width'],
                                'height' => $mediaInfo['height'],
                                'mime_type' => $mediaInfo['mime_type'],
                                'file_size' => $mediaInfo['file_size'],
                                'background' => $slideData['background'] ?? ($data['background'] ?? null),
                                'drawings' => $slideData['drawings'] ?? ($slideData['drawing'] ?? ($data['drawings'] ?? ($data['drawing'] ?? null))),
                                'framing' => $slideData['framing'] ?? ($slideData['crop'] ?? ($data['framing'] ?? ($data['crop'] ?? null))),
                            ],
                        ]);

                        // Enregistrement des stickers de cette diapositive
                        if (! empty($slideData['stickers']) && is_array($slideData['stickers'])) {
                            $this->attachStickers($slide, $slideData['stickers']);
                        }
                    }
                } else {
                    // Cas 2 : Diapositive unique standard (avec fichier direct 'file' ou 'media')
                    $file = $files['file'] ?? ($files['media'] ?? ($files['video'] ?? ($files['image'] ?? null)));
                    if (! $file instanceof UploadedFile) {
                        throw ValidationException::withMessages([
                            'file' => ['Un fichier image ou vidéo valide est obligatoire pour publier une Story.'],
                        ]);
                    }

                    $mediaInfo = $this->mediaStorageService->storeStoryMedia($file, $user);
                    $storedPaths[] = $mediaInfo['path'];

                    $thumbFile = $files['thumbnail'] ?? ($data['thumbnail'] ?? null);
                    $thumbPath = $mediaInfo['path'];
                    $thumbUrl = $mediaInfo['url'];
                    if ($thumbFile instanceof UploadedFile) {
                        $thumbInfo = $this->mediaStorageService->storeStoryMedia($thumbFile, $user);
                        $storedPaths[] = $thumbInfo['path'];
                        $thumbPath = $thumbInfo['path'];
                        $thumbUrl = $thumbInfo['url'];
                    } elseif (! empty($data['thumbnail_url'])) {
                        $thumbPath = $data['thumbnail_url'];
                        $thumbUrl = $data['thumbnail_url'];
                    }

                    $slide = StorySlide::create([
                        'story_id' => $story->id,
                        'media_type' => $mediaInfo['type'] === 'video' ? StoryMediaType::Video : StoryMediaType::Image,
                        'media_path' => $mediaInfo['path'],
                        'media_url' => $mediaInfo['url'],
                        'thumbnail_path' => $thumbPath,
                        'thumbnail_url' => $thumbUrl,
                        'filter' => $data['filter'] ?? null,
                        'caption' => $data['caption'] ?? null,
                        'duration' => $data['duration'] ?? ($mediaInfo['duration'] ?? ($mediaInfo['type'] === 'image' ? 5 : null)),
                        'order' => 0,
                        'sound_id' => $soundId,
                        'sound_metadata' => $soundMetadata,
                        'metadata' => [
                            'width' => $mediaInfo['width'],
                            'height' => $mediaInfo['height'],
                            'mime_type' => $mediaInfo['mime_type'],
                            'file_size' => $mediaInfo['file_size'],
                            'background' => $data['background'] ?? null,
                            'drawings' => $data['drawings'] ?? ($data['drawing'] ?? null),
                            'framing' => $data['framing'] ?? ($data['crop'] ?? null),
                        ],
                    ]);

                    if (! empty($data['stickers']) && is_array($data['stickers'])) {
                        $this->attachStickers($slide, $data['stickers']);
                    }
                }

                return $story->load(['slides.stickers', 'user']);
            });
        } catch (Throwable $e) {
            // Stratégie de compensation : Nettoyer immédiatement les fichiers créés sur le disque
            $disk = $this->mediaStorageService->getDisk();
            foreach ($storedPaths as $path) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable $delError) {
                    Log::warning("Impossible de supprimer le fichier orphelin après échec de publication de Story : {$path}", [
                        'error' => $delError->getMessage(),
                    ]);
                }
            }
            throw $e;
        }
    }

    /**
     * Attache les stickers créatifs à une diapositive avec validation géométrique relative [0..1].
     *
     * @param  array<array<string, mixed>>  $stickers
     */
    protected function attachStickers(StorySlide $slide, array $stickers): void
    {
        foreach ($stickers as $s) {
            $typeStr = $s['type'] ?? 'text';
            $type = StoryStickerType::tryFrom($typeStr) ?? StoryStickerType::Text;

            // Coordonnées relatives bornées entre 0.0 et 1.0
            $x = max(0.0, min(1.0, (float) ($s['x'] ?? 0.5)));
            $y = max(0.0, min(1.0, (float) ($s['y'] ?? 0.5)));
            $scale = max(0.1, min(10.0, (float) ($s['scale'] ?? 1.0)));
            $rotation = (float) ($s['rotation'] ?? 0.0);
            $payload = is_array($s['data'] ?? null) ? $s['data'] : [];

            // Validation spécifique au sondage
            if ($type === StoryStickerType::Poll) {
                if (empty($payload['question'])) {
                    throw ValidationException::withMessages([
                        'stickers' => ['La question du sondage ne peut pas être vide.'],
                    ]);
                }
                if (empty($payload['options']) || ! is_array($payload['options']) || count($payload['options']) < 2) {
                    $payload['options'] = ['Oui', 'Non'];
                }
            }

            StorySticker::create([
                'story_slide_id' => $slide->id,
                'type' => $type,
                'x' => $x,
                'y' => $y,
                'scale' => $scale,
                'rotation' => $rotation,
                'data' => $payload,
            ]);

            if ($type === StoryStickerType::Mention) {
                $targetUserId = $payload['user_id'] ?? null;
                $targetHandle = $payload['handle'] ?? ($payload['username'] ?? null);
                $target = null;
                if ($targetUserId) {
                    $target = User::find($targetUserId);
                } elseif ($targetHandle) {
                    $cleanHandle = ltrim((string) $targetHandle, '@');
                    $target = User::where('handle', $cleanHandle)->orWhere('name', $cleanHandle)->first();
                }
                if ($target && $target->id !== $slide->story?->user_id) {
                    try {
                        $target->notify(new SocialNotification('mention', $slide->story->user ?? auth()->user(), [
                            'story_id' => (string) $slide->story_id,
                            'slide_id' => (string) $slide->id,
                        ]));
                    } catch (Throwable $e) {
                        Log::warning("Notification mention story ignorée : {$e->getMessage()}");
                    }
                }
            }
        }
    }

    /**
     * Récupère le rail de Stories actif et accessible pour l'utilisateur connecté (RL / CR3).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRail(User $user): array
    {
        // 1. Récupérer toutes les stories actives accessibles
        $accessibleStories = Story::active()
            ->accessibleBy($user)
            ->with(['user', 'slides.stickers', 'views'])
            ->orderByDesc('published_at')
            ->get();

        if ($accessibleStories->isEmpty()) {
            return [];
        }

        // 2. Grouper les stories par auteur
        $groupedByUser = $accessibleStories->groupBy('user_id');

        $railItems = [];

        foreach ($groupedByUser as $authorId => $stories) {
            /** @var User $author */
            $author = $stories->first()->user;

            $totalStories = $stories->count();
            $unviewedCount = 0;

            foreach ($stories as $st) {
                if (! $st->views->contains('viewer_id', $user->id)) {
                    $unviewedCount++;
                }
            }

            $latestStory = $stories->first();
            $firstSlide = $latestStory->slides->first();

            $railItems[] = [
                'user' => [
                    'id' => $author->id,
                    'name' => $author->name,
                    'handle' => $author->handle,
                    'display_name' => $author->display_name ?? $author->name,
                    'avatar_url' => $author->avatar_url,
                    'is_verified' => (bool) $author->is_verified,
                    'is_mine' => $author->id === $user->id,
                ],
                'stories_count' => $totalStories,
                'unviewed_count' => $unviewedCount,
                'has_unviewed' => $unviewedCount > 0,
                'latest_story_id' => (string) $latestStory->id,
                'story_ids' => $stories->pluck('id')->map(fn ($sid) => (string) $sid)->values()->all(),
                'stories' => $stories->map(function ($st) use ($user) {
                    $first = $st->slides->first();

                    return [
                        'id' => (string) $st->id,
                        'audience' => $st->audience->value,
                        'published_at' => $st->published_at?->toIso8601String(),
                        'expires_at' => $st->expires_at?->toIso8601String(),
                        'is_viewed' => $st->views->contains('viewer_id', $user->id),
                        'media_url' => $first?->media_url,
                        'thumbnail_url' => $first?->thumbnail_url,
                    ];
                })->values()->all(),
                'latest_published_at' => $latestStory->published_at?->toIso8601String(),
                'latest_media_url' => $firstSlide?->media_url,
                'latest_thumbnail_url' => $firstSlide?->thumbnail_url,
                'audience' => $latestStory->audience->value,
            ];
        }

        // 3. Ordonnancement du rail :
        // - L'utilisateur connecté en tête s'il a des stories
        // - Ensuite les auteurs avec des stories NON VUES (ordonnés par date de publication récente)
        // - Enfin les auteurs dont toutes les stories ont été vues
        usort($railItems, function ($a, $b) use ($user) {
            if ($a['user']['id'] === $user->id) {
                return -1;
            }
            if ($b['user']['id'] === $user->id) {
                return 1;
            }

            if ($a['has_unviewed'] !== $b['has_unviewed']) {
                return $a['has_unviewed'] ? -1 : 1;
            }

            return strcmp($b['latest_published_at'], $a['latest_published_at']);
        });

        return $railItems;
    }

    /**
     * Récupère une Story individuelle par son identifiant avec vérification stricte d'accès et d'expiration.
     */
    public function getStory(string $id, User $viewer): Story
    {
        $story = Story::with(['user', 'slides.stickers', 'slides.sound'])->find($id);

        if (! $story) {
            abort(404, 'Story introuvable.');
        }

        // Vérification de l'expiration : si expirée et non propriétaire, 410 Gone
        if ($story->isExpired()) {
            if ($story->user_id === $viewer->id) {
                // Le propriétaire a accès pour consultation de son historique/archives
                return $story;
            }
            abort(410, 'Cette Story a expiré.');
        }

        if (! $story->isAccessibleBy($viewer)) {
            abort(404, 'Story indisponible ou restreinte.');
        }

        return $story;
    }

    /**
     * Récupère les diapositives d'une Story accessible.
     *
     * @return Collection<int, StorySlide>
     */
    public function getStorySlides(string $id, User $viewer): Collection
    {
        $story = $this->getStory($id, $viewer);

        return $story->slides;
    }

    /**
     * Récupère les Stories actives de l'utilisateur connecté.
     *
     * @return Collection<int, Story>
     */
    public function getMyActiveStories(User $user): Collection
    {
        return Story::where('user_id', $user->id)
            ->active()
            ->with(['slides.stickers', 'slides.sound'])
            ->orderByDesc('published_at')
            ->get();
    }

    /**
     * Récupère les Stories archivées de l'utilisateur connecté (paginées).
     */
    public function getArchivedStories(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Story::where('user_id', $user->id)
            ->archived()
            ->with(['slides.stickers', 'slides.sound'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Récupère le détail d'une Story appartenant à l'utilisateur avec ses statistiques complètes.
     */
    public function getMyStory(string $id, User $user): Story
    {
        $story = Story::where('user_id', $user->id)
            ->with(['slides.stickers', 'slides.sound', 'views.viewer'])
            ->find($id);

        if (! $story) {
            abort(404, 'Story introuvable.');
        }

        return $story;
    }

    /**
     * Supprime ou retire de la diffusion une Story appartenant à l'utilisateur.
     */
    public function deleteStory(string $id, User $user): bool
    {
        $story = Story::find($id);

        if (! $story) {
            abort(404, 'Story introuvable.');
        }

        if ($story->user_id !== $user->id) {
            abort(403, 'Vous n\'êtes pas autorisé à supprimer cette Story.');
        }

        return $story->delete();
    }

    /**
     * Enregistre une vue sur une Story avec déduplication et anonymisation IP.
     *
     * @return array{viewed: bool, views_count: int}
     */
    public function recordView(string $storyId, User $viewer, ?string $slideId = null, ?string $ip = null): array
    {
        $story = Story::find($storyId);
        if (! $story) {
            abort(404, 'Story introuvable.');
        }

        if ($story->isExpired()) {
            abort(410, 'Cette Story a expiré.');
        }

        if (! $story->isAccessibleBy($viewer)) {
            abort(404, 'Story indisponible ou restreinte.');
        }

        // Vérification de déduplication : une vue par utilisateur par Story
        $alreadyViewed = StoryView::where('story_id', $story->id)
            ->where('viewer_id', $viewer->id)
            ->exists();

        if ($alreadyViewed) {
            return [
                'viewed' => false,
                'views_count' => (int) $story->views_count,
            ];
        }

        // Hachage anonymisé de l'adresse IP
        $hashedIp = $ip ? hash('sha256', $ip.config('app.key')) : null;

        try {
            DB::transaction(function () use ($story, $viewer, $slideId, $hashedIp) {
                StoryView::create([
                    'story_id' => $story->id,
                    'story_slide_id' => $slideId,
                    'viewer_id' => $viewer->id,
                    'ip_address' => $hashedIp,
                    'viewed_at' => now(),
                ]);

                $story->increment('views_count');
            });
        } catch (QueryException $e) {
            // Protection concurrence : si une vue concurrente a été insérée simultanément
            return [
                'viewed' => false,
                'views_count' => (int) $story->fresh()->views_count,
            ];
        }

        return [
            'viewed' => true,
            'views_count' => (int) $story->fresh()->views_count,
        ];
    }

    /**
     * Enregistre un vote sur un sticker de sondage d'une diapositive.
     *
     * @return array{success: bool, votes_count: int, results: array<int, int>}
     */
    public function recordPollVote(string $slideId, string $stickerId, User $user, int $optionIndex): array
    {
        $slide = StorySlide::with('story')->find($slideId);
        if (! $slide || ! $slide->story) {
            abort(404, 'Diapositive introuvable.');
        }

        if ($slide->story->isExpired()) {
            abort(410, 'Cette Story a expiré.');
        }

        if (! $slide->story->isAccessibleBy($user)) {
            abort(404, 'Story indisponible.');
        }

        /** @var StorySticker|null $sticker */
        $sticker = StorySticker::where('id', $stickerId)
            ->where('story_slide_id', $slideId)
            ->first();

        if (! $sticker || $sticker->type !== StoryStickerType::Poll) {
            abort(404, 'Sticker de sondage introuvable sur cette diapositive.');
        }

        $options = $sticker->data['options'] ?? ['Oui', 'Non'];
        if (! isset($options[$optionIndex])) {
            throw ValidationException::withMessages([
                'option_index' => ['Option de vote invalide pour ce sondage.'],
            ]);
        }

        // Vérification de double vote (contrainte d'unicité stricte)
        $hasVoted = StoryPollVote::where('sticker_id', $stickerId)
            ->where('user_id', $user->id)
            ->exists();

        if ($hasVoted) {
            throw new HttpException(409, 'Vous avez déjà voté sur ce sondage.');
        }

        try {
            DB::transaction(function () use ($slide, $sticker, $user, $optionIndex) {
                StoryPollVote::create([
                    'story_slide_id' => $slide->id,
                    'sticker_id' => $sticker->id,
                    'user_id' => $user->id,
                    'option_index' => $optionIndex,
                ]);

                $slide->story->increment('poll_votes_count');
            });
        } catch (QueryException $e) {
            // Protection concurrence : rejet atomique en cas de tentative concurrente simultanée
            throw new HttpException(409, 'Vous avez déjà voté sur ce sondage.');
        }

        // Calcul des résultats actuels
        $allVotes = StoryPollVote::where('sticker_id', $stickerId)->get();
        $results = [];
        foreach ($options as $idx => $text) {
            $results[$idx] = $allVotes->where('option_index', $idx)->count();
        }

        return [
            'success' => true,
            'votes_count' => $allVotes->count(),
            'results' => $results,
        ];
    }

    /**
     * Enregistre une intention de réponse privée préparée pour la future messagerie (CR3).
     *
     * @param  array{
     *     type?: string,
     *     text?: ?string,
     *     reaction?: ?string,
     *     idempotency_key?: ?string,
     *     slide_id?: ?string
     * }  $data
     */
    public function createReply(string $storyId, User $user, array $data): StoryReply
    {
        $story = Story::with(['user', 'slides'])->find($storyId);
        if (! $story) {
            abort(404, 'Story introuvable.');
        }

        if ($story->isExpired()) {
            abort(410, 'Impossible de répondre à une Story expirée.');
        }

        if (! $story->isAccessibleBy($user)) {
            abort(404, 'Story indisponible.');
        }

        if (! $story->allow_replies) {
            abort(403, 'Les réponses privées sont désactivées pour cette Story.');
        }

        if ($story->user_id === $user->id) {
            throw ValidationException::withMessages([
                'reply' => ['Vous ne pouvez pas répondre à votre propre Story.'],
            ]);
        }

        if ($user->hasBlocked($story->user_id) || $user->isBlockedBy($story->user_id)) {
            abort(403, 'Action non autorisée en raison d\'une restriction entre les comptes.');
        }

        // Vérification de la diapositive ciblée si spécifiée
        $slideId = $data['slide_id'] ?? null;
        if ($slideId) {
            $validSlide = $story->slides->firstWhere('id', $slideId);
            if (! $validSlide) {
                throw ValidationException::withMessages([
                    'slide_id' => ['La diapositive ciblée n\'appartient pas à cette Story.'],
                ]);
            }
        }

        // Idempotence : Si une clé est fournie et qu'une réponse existe déjà, la renvoyer telle quelle
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = StoryReply::where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $typeVal = $data['type'] ?? 'text';
        $type = StoryReplyType::tryFrom($typeVal) ?? StoryReplyType::Text;

        $text = trim($data['text'] ?? '');
        $reaction = trim($data['reaction'] ?? '');

        if ($type === StoryReplyType::Text && empty($text)) {
            throw ValidationException::withMessages([
                'text' => ['Le texte de la réponse ne peut pas être vide.'],
            ]);
        }

        if ($type === StoryReplyType::Reaction && empty($reaction)) {
            throw ValidationException::withMessages([
                'reaction' => ['L\'émoji de réaction est obligatoire pour une réaction rapide.'],
            ]);
        }

        // Snapshot de la carte d'aperçu Story pour la future messagerie
        $firstSlide = $story->slides->first();
        $storyPreviewCard = [
            'story_id' => (string) $story->id,
            'author_handle' => $story->user->handle,
            'author_name' => $story->user->display_name ?? $story->user->name,
            'media_url' => $firstSlide?->media_url,
            'thumbnail_url' => $firstSlide?->thumbnail_url,
            'caption' => $firstSlide?->caption,
            'filter' => $firstSlide?->filter,
            'published_at' => $story->published_at?->toIso8601String(),
        ];

        try {
            $reply = DB::transaction(function () use ($story, $user, $type, $text, $reaction, $idempotencyKey, $storyPreviewCard, $slideId) {
                $created = StoryReply::create([
                    'story_id' => $story->id,
                    'story_slide_id' => $slideId,
                    'story_author_id' => $story->user_id,
                    'user_id' => $user->id,
                    'type' => $type,
                    'text' => $type === StoryReplyType::Text ? $text : null,
                    'reaction' => $type === StoryReplyType::Reaction ? $reaction : null,
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $storyPreviewCard,
                ]);

                $story->increment('replies_count');

                return $created;
            });
        } catch (QueryException $e) {
            // Protection concurrence : si une insertion concurrente avec même clé idempotente est survenue
            if ($idempotencyKey) {
                $existing = StoryReply::where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }

        // Dispatch de l'événement métier STRICTEMENT APRÈS le commit de la transaction
        StoryReplyCreated::dispatch($reply);

        // Création du message dans la conversation privée (MS / Point 36)
        try {
            $threadService = app(MessageThreadService::class);
            $conversation = $threadService->getOrCreateDirectConversation($user, $story->user_id);
            $messageService = app(MessageService::class);
            $messageService->sendMessage($conversation, $user, [
                'type' => MessageType::StoryReply,
                'body' => $type === StoryReplyType::Text ? $text : $reaction,
                'metadata' => [
                    'story_id' => (string) $story->id,
                    'story_reply_id' => $reply->id,
                    'preview' => $storyPreviewCard,
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('Échec de création du message pour la réponse story : '.$e->getMessage());
        }

        return $reply;
    }

    /**
     * Récupère la liste des spectateurs d'une Story (strictement réservé au propriétaire).
     */
    public function getViewers(string $storyId, User $user, int $perPage = 20): LengthAwarePaginator
    {
        $story = Story::find($storyId);
        if (! $story) {
            abort(404, 'Story introuvable.');
        }

        if ($story->user_id !== $user->id) {
            abort(403, 'Seul le propriétaire de la Story peut consulter la liste des spectateurs.');
        }

        return StoryView::where('story_id', $story->id)
            ->with([
                'viewer' => function ($q) {
                    $q->select(['id', 'name', 'handle', 'display_name', 'avatar_url', 'is_verified']);
                },
                'story.slides',
            ])
            ->orderByDesc('viewed_at')
            ->paginate($perPage);
    }

    /**
     * Enregistre un signalement pour modération sur une Story.
     */
    public function reportStory(string $storyId, User $reporter, string $reason, ?string $details = null): StoryReport
    {
        $story = Story::find($storyId);
        if (! $story) {
            abort(404, 'Story introuvable.');
        }

        return StoryReport::create([
            'story_id' => $story->id,
            'reporter_id' => $reporter->id,
            'reason' => $reason,
            'details' => $details,
            'status' => 'pending',
        ]);
    }

    public function likeStory(string $id, User $user): array
    {
        $story = $this->getStory($id, $user);

        StoryLike::firstOrCreate([
            'story_id' => $story->id,
            'user_id' => $user->id,
        ]);

        $count = StoryLike::where('story_id', $story->id)->count();
        $story->update(['likes_count' => $count]);

        if ($story->user_id !== $user->id) {
            try {
                $story->user->notify(new SocialNotification('like', $user, [
                    'story_id' => (string) $story->id,
                ]));
            } catch (Throwable $e) {
                Log::warning("Notification like story échouée : {$e->getMessage()}");
            }
        }

        return [
            'is_liked' => true,
            'likes_count' => $count,
        ];
    }

    public function unlikeStory(string $id, User $user): array
    {
        $story = $this->getStory($id, $user);

        StoryLike::where('story_id', $story->id)
            ->where('user_id', $user->id)
            ->delete();

        $count = StoryLike::where('story_id', $story->id)->count();
        $story->update(['likes_count' => $count]);

        return [
            'is_liked' => false,
            'likes_count' => $count,
        ];
    }

    public function answerSticker(string $stickerId, User $user, string $answer): array
    {
        $sticker = StorySticker::with(['slide.story.user'])->findOrFail($stickerId);
        $story = $sticker->slide->story;

        DB::table('story_sticker_answers')->insert([
            'id' => (string) Str::uuid(),
            'story_id' => $story->id,
            'slide_id' => $sticker->story_slide_id ?? $sticker->slide->id,
            'sticker_id' => $sticker->id,
            'user_id' => $user->id,
            'type' => 'question',
            'answer' => $answer,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($story->user_id !== $user->id) {
            try {
                $story->user->notify(new SocialNotification('story_question_answer', $user, [
                    'story_id' => (string) $story->id,
                    'sticker_id' => (string) $sticker->id,
                    'text' => $answer,
                ]));
            } catch (Throwable $e) {
                Log::warning("Notification question answer échouée : {$e->getMessage()}");
            }
        }

        return [
            'success' => true,
            'message' => 'Réponse enregistrée avec succès.',
        ];
    }

    public function remindSticker(string $stickerId, User $user): array
    {
        $sticker = StorySticker::with(['slide.story'])->findOrFail($stickerId);
        $story = $sticker->slide->story;

        DB::table('story_sticker_answers')->insert([
            'id' => (string) Str::uuid(),
            'story_id' => $story->id,
            'slide_id' => $sticker->story_slide_id ?? $sticker->slide->id,
            'sticker_id' => $sticker->id,
            'user_id' => $user->id,
            'type' => 'countdown_reminder',
            'answer' => 'remind',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $user->notify(new SocialNotification('countdown_remind', $user, [
                'story_id' => (string) $story->id,
                'sticker_id' => (string) $sticker->id,
            ]));
        } catch (Throwable $e) {
            Log::warning("Notification countdown reminder échouée : {$e->getMessage()}");
        }

        return [
            'success' => true,
            'message' => 'Rappel de compte à rebours activé.',
        ];
    }

    public function getSettings(User $user): array
    {
        $privacy = $user->privacy_settings ?? [];

        return $privacy['stories'] ?? [
            'allow_replies' => 'everyone',
            'save_to_archive' => true,
            'save_to_gallery' => false,
            'allow_sharing' => true,
        ];
    }

    public function updateSettings(User $user, array $settings): array
    {
        $privacy = $user->privacy_settings ?? [];
        $current = $privacy['stories'] ?? [
            'allow_replies' => 'everyone',
            'save_to_archive' => true,
            'save_to_gallery' => false,
            'allow_sharing' => true,
        ];

        $merged = array_merge($current, $settings);
        $privacy['stories'] = $merged;

        $user->update(['privacy_settings' => $privacy]);

        return $merged;
    }

    public function exportStory(string $id, User $user): array
    {
        $story = $this->getStory($id, $user);
        $firstSlide = $story->slides->first();

        return [
            'story_id' => (string) $story->id,
            'media_url' => $firstSlide?->media_url,
            'sound' => $firstSlide?->sound_metadata,
            'export_url' => $firstSlide?->media_url,
            'has_sound' => ! empty($firstSlide?->sound_metadata),
            'status' => 'ready',
        ];
    }
}
