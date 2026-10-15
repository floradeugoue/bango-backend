<?php

namespace App\Http\Controllers\Story;

use App\Http\Controllers\Controller;
use App\Http\Requests\Story\StoreStoryRequest;
use App\Http\Requests\Story\StoryReplyRequest;
use App\Http\Requests\Story\VotePollRequest;
use App\Http\Resources\Story\StoryReplyResource;
use App\Http\Resources\Story\StoryResource;
use App\Http\Resources\Story\StorySlideResource;
use App\Http\Resources\Story\StoryViewerResource;
use App\Services\Story\StoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoryController extends Controller
{
    public function __construct(protected StoryService $storyService) {}

    /**
     * Récupérer le rail de Stories accessible pour l'utilisateur authentifié (CR3).
     */
    public function rail(Request $request): JsonResponse
    {
        $items = $this->storyService->getRail($request->user());

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * Créer et publier une Story avec médias, stickers et son (CR3).
     */
    public function store(StoreStoryRequest $request): JsonResponse
    {
        $story = $this->storyService->createStory(
            $request->user(),
            $request->validated(),
            $request->allFiles()
        );

        return (new StoryResource($story))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Consulter une Story accessible par son identifiant unique.
     */
    public function show(string $id, Request $request): StoryResource
    {
        $story = $this->storyService->getStory($id, $request->user());

        return new StoryResource($story);
    }

    /**
     * Récupérer les diapositives créatives d'une Story accessible.
     */
    public function slides(string $id, Request $request): AnonymousResourceCollection
    {
        $slides = $this->storyService->getStorySlides($id, $request->user());

        return StorySlideResource::collection($slides);
    }

    /**
     * Récupérer les Stories actives publiées par l'utilisateur connecté.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $stories = $this->storyService->getMyActiveStories($request->user());

        return StoryResource::collection($stories);
    }

    /**
     * Récupérer les Stories archivées de l'utilisateur connecté (paginées).
     */
    public function archived(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->query('per_page', 20);
        $stories = $this->storyService->getArchivedStories($request->user(), $perPage);

        return StoryResource::collection($stories);
    }

    /**
     * Consulter le détail d'une Story appartenant à l'utilisateur avec statistiques complètes.
     */
    public function myStory(string $id, Request $request): StoryResource
    {
        $story = $this->storyService->getMyStory($id, $request->user());

        return new StoryResource($story);
    }

    /**
     * Supprimer définitivement une Story de l'utilisateur connecté.
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $this->storyService->deleteStory($id, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Story supprimée avec succès.',
        ]);
    }

    /**
     * Consulter la liste des spectateurs d'une Story (strictement réservé au propriétaire).
     */
    public function viewers(string $id, Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->query('per_page', 20);
        $viewers = $this->storyService->getViewers($id, $request->user(), $perPage);

        return StoryViewerResource::collection($viewers);
    }

    /**
     * Enregistrer la consultation d'une Story avec déduplication et anonymisation IP.
     */
    public function view(string $id, Request $request): JsonResponse
    {
        $result = $this->storyService->recordView(
            $id,
            $request->user(),
            $request->input('slide_id'),
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Enregistrer un vote sur un sondage d'une diapositive de Story.
     */
    public function pollVote(string $slideId, string $stickerId, VotePollRequest $request): JsonResponse
    {
        $result = $this->storyService->recordPollVote(
            $slideId,
            $stickerId,
            $request->user(),
            (int) $request->validated('option_index')
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Enregistrer une intention de réponse privée préparée pour la future messagerie (CR3).
     */
    public function reply(string $id, StoryReplyRequest $request): JsonResponse
    {
        $reply = $this->storyService->createReply(
            $id,
            $request->user(),
            $request->validated()
        );

        return (new StoryReplyResource($reply))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Signaler une Story pour modération.
     */
    public function report(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:64'],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = $this->storyService->reportStory(
            $id,
            $request->user(),
            $validated['reason'],
            $validated['details'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Signalement enregistré pour examen.',
            'data' => [
                'id' => $report->id,
            ],
        ], 201);
    }
}
