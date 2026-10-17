<?php

namespace App\Http\Controllers\Admin\Shell;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    /**
     * GET /notifications
     * Flux paginé des notifications de l'opérateur connecté avec filtrage et contrôle strict de propriété.
     */
    public function index(Request $request): JsonResponse
    {
        $operator = $request->user();
        $perPage = min((int) ($request->input('per_page', 15)), 50);
        $filter = $request->input('status'); // unread, read, all

        $query = $operator->notifications()->orderBy('created_at', 'desc');

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($notif) => [
                'id' => $notif->id,
                'type' => class_basename($notif->type),
                'data' => $notif->data,
                'read_at' => $notif->read_at?->toIso8601String(),
                'is_read' => $notif->read_at !== null,
                'created_at' => $notif->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'unread_count' => $operator->unreadNotifications()->count(),
            ],
        ], 200);
    }

    /**
     * POST /notifications/read-all
     * Marque comme lues toutes les notifications non lues de l'opérateur de manière idempotente.
     */
    public function readAll(Request $request): JsonResponse
    {
        $operator = $request->user();

        // Mise à jour ciblée strictement sur les notifications de cet opérateur
        $affected = $operator->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Toutes vos notifications ont été marquées comme lues.',
            'marked_as_read_count' => $affected,
        ], 200);
    }
}
