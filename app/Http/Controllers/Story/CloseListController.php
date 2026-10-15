<?php

namespace App\Http\Controllers\Story;

use App\Http\Controllers\Controller;
use App\Http\Requests\Story\UpdateCloseListRequest;
use App\Services\Story\CloseListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CloseListController extends Controller
{
    public function __construct(protected CloseListService $closeListService) {}

    /**
     * Récupérer la liste choisie (close friends) de l'utilisateur connecté.
     */
    public function index(Request $request): JsonResponse
    {
        $users = $this->closeListService->getCloseList($request->user());

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Remplacer la liste choisie de façon atomique et validée.
     */
    public function update(UpdateCloseListRequest $request): JsonResponse
    {
        $users = $this->closeListService->replaceCloseList(
            $request->user(),
            $request->validated('user_ids')
        );

        return response()->json([
            'success' => true,
            'message' => 'Liste choisie mise à jour avec succès.',
            'data' => $users,
        ]);
    }
}
