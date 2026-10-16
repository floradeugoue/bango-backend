<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\Notification\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * Liste paginée des notifications in-app de l'utilisateur authentifié.
     *
     * GET /api/notifications
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $category = $request->query('category', 'all');
        $unreadOnly = $request->boolean('unread_only');
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $query = $user->notifications()->latest();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        if ($category !== 'all') {
            match ($category) {
                'immo' => $query->where(function ($q) {
                    $q->where('type', 'like', '%Notifications\\\\Visit%')
                        ->orWhere('type', 'like', '%Notifications\\\\Delegation%')
                        ->orWhere('data', 'like', '%"category":"immo"%')
                        ->orWhere('data', 'like', '%"type":"visit_%')
                        ->orWhere('data', 'like', '%"type":"delegation_%')
                        ->orWhere('data', 'like', '%"type":"listing_%');
                }),
                'social' => $query->where(function ($q) {
                    $q->where('type', 'like', '%Notifications\\\\Message%')
                        ->orWhere('type', 'like', '%PageMember%')
                        ->orWhere('data', 'like', '%"category":"social"%')
                        ->orWhere('data', 'like', '%"type":"new_message%')
                        ->orWhere('data', 'like', '%"type":"post_%')
                        ->orWhere('data', 'like', '%"type":"reel_%')
                        ->orWhere('data', 'like', '%"type":"story_%');
                }),
                'account' => $query->where(function ($q) {
                    $q->where('type', 'like', '%Notifications\\\\Subscription%')
                        ->orWhere('type', 'like', '%Notifications\\\\Boost%')
                        ->orWhere('type', 'like', '%Notifications\\\\Kyc%')
                        ->orWhere('type', 'like', '%PageCertification%')
                        ->orWhere('type', 'like', '%PageSuspended%')
                        ->orWhere('data', 'like', '%"category":"account"%')
                        ->orWhere('data', 'like', '%"type":"campaign_%')
                        ->orWhere('data', 'like', '%"type":"kyc_%')
                        ->orWhere('data', 'like', '%"type":"subscription_%')
                        ->orWhere('data', 'like', '%"type":"badge_%');
                }),
                default => null,
            };
        }

        $notifications = $query->paginate($perPage);
        $unreadCount = $user->unreadNotifications()->count();

        return NotificationResource::collection($notifications)->additional([
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Marque une notification individuelle comme lue.
     *
     * POST /api/notifications/{id}/read
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        // Vérifier si la notification existe dans le système global pour détecter les tentatives d'accès interdit
        $globalNotification = DatabaseNotification::find($id);
        if (! $globalNotification) {
            return response()->json([
                'message' => 'Notification introuvable.',
            ], 404);
        }

        if ((int) $globalNotification->notifiable_id !== (int) $user->id || $globalNotification->notifiable_type !== $user->getMorphClass()) {
            return response()->json([
                'message' => 'Accès non autorisé à cette notification.',
            ], 403);
        }

        if ($globalNotification->read_at === null) {
            $globalNotification->markAsRead();
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marquée comme lue.',
            'unread_count' => $user->unreadNotifications()->count(),
        ], 200);
    }

    /**
     * Marque toutes les notifications de l'utilisateur comme lues.
     *
     * POST /api/notifications/read ou POST /api/notifications/mark-all-read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Toutes les notifications ont été marquées comme lues.',
            'unread_count' => 0,
        ], 200);
    }
}
