<?php

namespace App\Http\Controllers\Upload;

use App\Exceptions\Upload\TusUploadException;
use App\Http\Controllers\Controller;
use App\Models\Upload\TusUploadSession;
use App\Services\Upload\TusUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TusUploadController extends Controller
{
    public function __construct(
        protected TusUploadService $tusService
    ) {}

    /**
     * Options et découverte des fonctionnalités du protocole Tus v1.0.0.
     *
     * OPTIONS /api/uploads/tus
     * OPTIONS /api/uploads/tus/{id}
     */
    public function options(): Response
    {
        return response('', 204, [
            'Tus-Resumable' => TusUploadService::TUS_VERSION,
            'Tus-Version' => TusUploadService::TUS_VERSION,
            'Tus-Max-Size' => (string) TusUploadService::MAX_UPLOAD_SIZE,
            'Tus-Extension' => 'creation,termination,expiration',
            'Access-Control-Expose-Headers' => 'Tus-Resumable, Tus-Version, Tus-Max-Size, Tus-Extension, Upload-Offset, Upload-Length, Upload-Expires, Location',
        ]);
    }

    /**
     * Crée une nouvelle session de téléversement Tus (Creation Extension).
     *
     * POST /api/uploads/tus
     */
    public function create(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Lecture de la taille attendue depuis l'en-tête Upload-Length ou le corps JSON
        $uploadLength = $request->header('Upload-Length');
        if ($uploadLength === null && $request->has('upload_length')) {
            $uploadLength = $request->input('upload_length');
        }

        if ($uploadLength === null || ! is_numeric($uploadLength)) {
            throw new TusUploadException('L\'en-tête Upload-Length est requis pour initialiser un téléversement Tus.', 400);
        }

        // 2. Décodage des métadonnées Tus
        $metadataHeader = $request->header('Upload-Metadata');
        $metadata = $this->tusService->parseUploadMetadata($metadataHeader);

        // Fusion avec les paramètres du corps si fournis
        if ($request->has('metadata') && is_array($request->input('metadata'))) {
            $metadata = array_merge($metadata, $request->input('metadata'));
        }
        if ($request->has('kind')) {
            $metadata['kind'] = (string) $request->input('kind');
        }

        $session = $this->tusService->createSession($user, (int) $uploadLength, $metadata);
        $location = route('api.uploads.tus.head', ['id' => $session->id]);

        return response()->json([
            'id' => (string) $session->id,
            'upload_length' => (int) $session->upload_length,
            'upload_offset' => 0,
            'location' => $location,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ], 201, [
            'Location' => $location,
            'Tus-Resumable' => TusUploadService::TUS_VERSION,
            'Upload-Expires' => $session->expires_at?->toRfc7231String(),
            'Access-Control-Expose-Headers' => 'Location, Tus-Resumable, Upload-Expires, Upload-Offset, Upload-Length',
        ]);
    }

    /**
     * Récupère l'offset actuel et l'état de la ressource Tus (Core Protocol).
     *
     * HEAD /api/uploads/tus/{id}
     */
    public function head(Request $request, string $id): Response|JsonResponse
    {
        $user = $request->user();

        /** @var TusUploadSession $session */
        $session = TusUploadSession::findOrFail($id);

        if ((int) $session->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Accès non autorisé à cette session de téléversement.');
        }

        $offset = $this->tusService->getOffset($session);

        $headers = [
            'Upload-Offset' => (string) $offset,
            'Upload-Length' => (string) $session->upload_length,
            'Tus-Resumable' => TusUploadService::TUS_VERSION,
            'Cache-Control' => 'no-store',
            'Upload-Expires' => $session->expires_at?->toRfc7231String(),
            'Access-Control-Expose-Headers' => 'Upload-Offset, Upload-Length, Tus-Resumable, Upload-Expires, Cache-Control',
        ];

        // Pour les requêtes HEAD standard, le protocole prescrit une réponse sans corps
        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        // Si appelée en GET (par exemple pour inspection client), retourner les données en JSON
        return response()->json([
            'id' => (string) $session->id,
            'upload_offset' => $offset,
            'upload_length' => (int) $session->upload_length,
            'status' => $session->status,
            'is_completed' => $session->isCompleted(),
            'progress_percentage' => $session->progress,
            'final_url' => $session->final_url,
        ], 200, $headers);
    }

    /**
     * Dépôt d'un fragment binaire à l'offset confirmé (Core Protocol).
     *
     * PATCH /api/uploads/tus/{id}
     */
    public function patch(Request $request, string $id): Response|JsonResponse
    {
        $user = $request->user();

        /** @var TusUploadSession $session */
        $session = TusUploadSession::findOrFail($id);

        if ((int) $session->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Accès non autorisé à cette session de téléversement.');
        }

        // 1. Contrôle du Content-Type
        $contentType = $request->header('Content-Type', '');
        if (! str_contains($contentType, 'application/offset+octet-stream') && ! str_contains($contentType, 'application/octet-stream')) {
            throw new TusUploadException(
                'L\'en-tête Content-Type doit être application/offset+octet-stream pour le protocole Tus.',
                415,
                'INVALID_CONTENT_TYPE'
            );
        }

        // 2. Contrôle de l'en-tête Upload-Offset
        $offsetHeader = $request->header('Upload-Offset');
        if ($offsetHeader === null || ! is_numeric($offsetHeader)) {
            throw new TusUploadException('L\'en-tête Upload-Offset est requis pour téléverser un fragment.', 400);
        }

        $clientOffset = (int) $offsetHeader;
        $chunkData = (string) $request->getContent();

        $updatedSession = $this->tusService->appendChunk($session, $clientOffset, $chunkData);

        return response('', 204, [
            'Upload-Offset' => (string) $updatedSession->upload_offset,
            'Tus-Resumable' => TusUploadService::TUS_VERSION,
            'Upload-Expires' => $updatedSession->expires_at?->toRfc7231String(),
            'Access-Control-Expose-Headers' => 'Upload-Offset, Tus-Resumable, Upload-Expires',
        ]);
    }

    /**
     * Annule et supprime une session de téléversement (Termination Extension).
     *
     * DELETE /api/uploads/tus/{id}
     */
    public function delete(Request $request, string $id): Response
    {
        $user = $request->user();

        /** @var TusUploadSession $session */
        $session = TusUploadSession::findOrFail($id);

        if ((int) $session->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Accès non autorisé à cette session de téléversement.');
        }

        $this->tusService->terminateSession($session);

        return response('', 204, [
            'Tus-Resumable' => TusUploadService::TUS_VERSION,
        ]);
    }

    /**
     * Finalise explicitement une session et retourne l'entité média promue.
     *
     * POST /api/uploads/tus/{id}/finalize
     */
    public function finalize(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        /** @var TusUploadSession $session */
        $session = TusUploadSession::findOrFail($id);

        if ((int) $session->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'Accès non autorisé à cette session de téléversement.');
        }

        $media = $this->tusService->finalizeSession($session);

        return response()->json([
            'success' => true,
            'message' => 'Fichier téléversé et validé avec succès.',
            'data' => $media,
        ], 200, [
            'Tus-Resumable' => TusUploadService::TUS_VERSION,
        ]);
    }
}
