<?php

namespace App\Services\Square;

use App\Enums\Square\PostAudience;
use App\Enums\Square\PostCommentsBy;
use App\Enums\Square\PostMediaKind;
use App\Models\Page\Page;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\Square\PostTag;
use App\Models\User;
use App\Services\Admin\AutomatedTextFilterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PostCreationService
{
    public function __construct(
        protected HashtagExtractionService $hashtagService
    ) {}

    /**
     * Crée une publication de manière atomique et idempotente.
     *
     * @param  array{
     *     body?: ?string,
     *     audience?: string|PostAudience,
     *     comments_by?: string|PostCommentsBy,
     *     comments_closed?: bool,
     *     allow_share?: bool,
     *     hide_likes?: bool,
     *     as_page_id?: ?int,
     *     place?: ?array{label?: string, detail?: string, city?: string, neighbourhood?: string},
     *     media?: array<int, array{url: string, type?: string, mime_type?: ?string, file_size?: ?int, width?: ?int, height?: ?int, duration?: ?int, display_order?: int, edit_metadata?: ?array}>,
     *     tags?: array<int, array{account_id: int|string, x: float, y: float, media_index?: int}>,
     *     idempotency_key?: ?string
     * }  $data
     */
    public function create(User $user, array $data): Post
    {
        // 1. Gestion de l'idempotence : si la clé existe déjà pour cet utilisateur, retourner le post existant
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = Post::where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with(['media.tags.user', 'hashtags', 'user'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $body = isset($data['body']) ? trim($data['body']) : null;
        $mediaList = $data['media'] ?? [];

        // Validation métier : un post doit comporter au moins du texte ou au moins un média
        if (empty($body) && empty($mediaList)) {
            throw new InvalidArgumentException('Une publication doit contenir au moins du texte ou au moins un média.');
        }

        // Maximum 10 médias par publication (conforme à CR-02 et Square carrousel)
        if (count($mediaList) > 10) {
            throw new InvalidArgumentException('Une publication ne peut pas comporter plus de 10 médias.');
        }

        return DB::transaction(function () use ($user, $data, $body, $mediaList, $idempotencyKey) {
            $place = $data['place'] ?? null;

            // Détermination de l'audience et des options
            $audience = $data['audience'] ?? PostAudience::Public->value;
            if ($audience instanceof PostAudience) {
                $audience = $audience->value;
            }

            $commentsBy = $data['comments_by'] ?? PostCommentsBy::Everyone->value;
            if ($commentsBy instanceof PostCommentsBy) {
                $commentsBy = $commentsBy->value;
            }

            $asPageId = $data['as_page_id'] ?? null;
            if ($asPageId) {
                /** @var Page|null $page */
                $page = Page::find($asPageId);
                if (! $page || ! $page->canUserPublish($user)) {
                    throw ValidationException::withMessages([
                        'as_page_id' => ['Vous n\'êtes pas autorisé à publier au nom de cette page.'],
                    ]);
                }
            }

            // Création du post principal
            $post = Post::create([
                'user_id' => $user->id,
                'page_id' => $asPageId,
                'body' => $body,
                'audience' => $audience,
                'comments_by' => $commentsBy,
                'comments_closed' => (bool) ($data['comments_closed'] ?? false),
                'allow_share' => (bool) ($data['allow_share'] ?? true),
                'hide_likes' => (bool) ($data['hide_likes'] ?? false),
                'is_edited' => false,
                'is_archived' => false,
                'place_label' => $place['label'] ?? null,
                'place_detail' => $place['detail'] ?? null,
                'place_city' => $place['city'] ?? null,
                'place_neighbourhood' => $place['neighbourhood'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Enregistrement des médias et indexation pour les tags
            $createdMediaMap = []; // index => PostMedia

            foreach ($mediaList as $index => $mediaItem) {
                $order = $mediaItem['display_order'] ?? $index;
                $mediaType = $mediaItem['type'] ?? PostMediaKind::Photo->value;

                $postMedia = PostMedia::create([
                    'post_id' => $post->id,
                    'media_url' => $mediaItem['url'],
                    'media_type' => $mediaType,
                    'mime_type' => $mediaItem['mime_type'] ?? null,
                    'file_size' => $mediaItem['file_size'] ?? null,
                    'width' => $mediaItem['width'] ?? null,
                    'height' => $mediaItem['height'] ?? null,
                    'duration' => $mediaItem['duration'] ?? null,
                    'display_order' => $order,
                    'edit_metadata' => $mediaItem['edit_metadata'] ?? null,
                ]);

                $createdMediaMap[$index] = $postMedia;
            }

            // Enregistrement des tags de personnes (CR-08)
            $tags = $data['tags'] ?? [];
            foreach ($tags as $tag) {
                $targetUserId = (int) $tag['account_id'];
                $mediaIndex = (int) ($tag['media_index'] ?? 0);
                $x = (float) $tag['x'];
                $y = (float) $tag['y'];

                // Vérification des coordonnées relatives (0.0 .. 1.0)
                if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
                    continue; // Ignore les coordonnées invalides
                }

                // Associer au bon média du carrousel
                $targetMedia = $createdMediaMap[$mediaIndex] ?? ($createdMediaMap[0] ?? null);
                if (! $targetMedia) {
                    continue;
                }

                // Vérification de l'existence de l'utilisateur ciblé
                if (! User::where('id', $targetUserId)->exists()) {
                    continue;
                }

                PostTag::firstOrCreate([
                    'post_media_id' => $targetMedia->id,
                    'user_id' => $targetUserId,
                ], [
                    'x' => $x,
                    'y' => $y,
                ]);
            }

            // Extraction et liaison des hashtags (CR-07)
            if ($body) {
                $this->hashtagService->syncPostHashtags($post, $body);

                // Point 19 : Déclenchement du filtre textuel automatique
                app(AutomatedTextFilterService::class)
                    ->scanText($body, 'post', $post->id, $user);
            }

            return $post->load(['media.tags.user', 'hashtags', 'user']);
        });
    }
}
