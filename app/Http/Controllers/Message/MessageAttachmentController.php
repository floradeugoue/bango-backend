<?php

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Models\Message\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class MessageAttachmentController extends Controller
{
    /**
     * Accès sécurisé, streaming et téléchargement d'une pièce jointe ou note vocale privée.
     *
     * GET /api/messages/{id}/attachment
     * GET /api/messages/{id}/voice
     */
    public function show(Request $request, string $id): Response
    {
        /** @var Message $message */
        $message = Message::query()->with('conversation')->findOrFail($id);

        // Si le message a été rétracté ou n'a pas de pièce jointe
        if ($message->is_retracted || ! $message->attachment_path) {
            abort(404, 'Fichier introuvable.');
        }

        // Vérification de l'accès :
        // 1. Soit l'utilisateur est authentifié et autorisé à voir la conversation
        // 2. Soit la requête possède une URL temporaire signée valide (utilisée par les lecteurs audio natifs)
        $user = $request->user('api') ?? $request->user();

        if ($user) {
            // L'utilisateur connecté doit appartenir à la conversation ou être admin
            if (! $user->isAdmin()) {
                Gate::forUser($user)->authorize('view', $message->conversation);
            }
        } elseif (! $request->hasValidSignature()) {
            abort(401, 'Authentification requise pour accéder à ce fichier privé.');
        }

        $privateDisk = config('media.private_disk', 'local');
        $publicDisk = config('media.disk', 'public');

        if (Storage::disk($privateDisk)->exists($message->attachment_path)) {
            $disk = $privateDisk;
        } elseif (Storage::disk($publicDisk)->exists($message->attachment_path)) {
            // Rétrocompatibilité : fichier pas encore migré vers le stockage privé
            $disk = $publicDisk;
        } else {
            abort(404, 'Fichier introuvable sur le stockage.');
        }

        $mime = $message->attachment_mime ?: (Storage::disk($disk)->mimeType($message->attachment_path) ?: 'application/octet-stream');
        $filename = basename($message->attachment_path);

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ];

        return Storage::disk($disk)->response($message->attachment_path, $filename, $headers);
    }
}
