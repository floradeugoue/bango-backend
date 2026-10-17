<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\ModerationSoundActionRequest;
use App\Models\Admin\AdminAuditLog;
use App\Services\Admin\AdminSoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminModerationSoundController extends Controller
{
    public function __construct(
        protected AdminSoundService $soundService
    ) {}

    /**
     * Liste des sons du catalogue et leur état de modération (GET /moderation/sounds).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'genre' => $request->query('genre'),
            'rights_status' => $request->query('rights_status'),
            'is_published' => $request->has('is_published') ? filter_var($request->query('is_published'), FILTER_VALIDATE_BOOLEAN) : null,
            'is_removed' => $request->has('is_removed') ? filter_var($request->query('is_removed'), FILTER_VALIDATE_BOOLEAN) : null,
            'sort_by' => $request->query('sort_by', 'latest'),
        ];

        $perPage = (int) $request->query('per_page', 25);
        $sounds = $this->soundService->listSounds($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $sounds->items(),
            'pagination' => [
                'current_page' => $sounds->currentPage(),
                'per_page' => $sounds->perPage(),
                'total' => $sounds->total(),
                'last_page' => $sounds->lastPage(),
            ],
        ]);
    }

    /**
     * Action de modération sur un son (POST /moderation/sounds/{id}/action).
     */
    public function action(ModerationSoundActionRequest $request, string $id): JsonResponse
    {
        $sound = $this->soundService->getSound($id);
        $operator = $request->user();
        $action = $request->input('action');
        $reason = $request->input('reason');

        switch ($action) {
            case 'validate':
                $sound = $this->soundService->setRightsStatus($sound, 'verified');
                $sound = $this->soundService->setPublication($sound, true);
                $message = "Le son '{$sound->title}' a été validé et publié avec succès.";
                break;

            case 'hide':
            case 'disable':
                $sound = $this->soundService->removeFromCatalog($sound);
                $message = "Le son '{$sound->title}' a été désactivé et masqué du catalogue.";
                break;

            case 'restore':
                $sound = $this->soundService->restoreToCatalog($sound);
                $sound = $this->soundService->setPublication($sound, true);
                $message = "Le son '{$sound->title}' a été restauré avec succès dans le catalogue.";
                break;

            default:
                return response()->json([
                    'success' => false,
                    'message' => "Action non prise en charge : {$action}",
                ], 422);
        }

        AdminAuditLog::logEvent('moderation.sound.action', $operator, $request->ip(), $request->userAgent(), [
            'sound_id' => $sound->id,
            'action' => $action,
            'reason' => $reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'id' => $sound->id,
                'title' => $sound->title,
                'artist' => $sound->artist,
                'rights_status' => $sound->rights_status,
                'is_published' => $sound->is_published,
                'is_removed' => $sound->is_removed,
                'updated_at' => $sound->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
