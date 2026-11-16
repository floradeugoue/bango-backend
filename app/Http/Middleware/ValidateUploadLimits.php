<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class ValidateUploadLimits
{
    /**
     * Intercepte et valide les limites d'upload PHP avant l'authentification Passport
     * et avant toute conversion PSR-7, évitant le crash 500 "Unable to open ... using mode r".
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Contrôle du post_max_size (si CONTENT_LENGTH dépasse post_max_size)
        $maxPostSize = $this->getPostMaxSize();
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

        if ($maxPostSize > 0 && $contentLength > $maxPostSize) {
            return response()->json([
                'message' => 'Les données envoyées dépassent la taille maximale autorisée par le serveur (post_max_size).',
                'error' => 'post_too_large',
                'code' => 'UPLOAD_ERR_POST_SIZE',
                'max_size' => ini_get('post_max_size') ?: null,
                'errors' => [
                    'file' => ['Le fichier dépasse la taille maximale autorisée par le serveur.'],
                ],
            ], 413);
        }

        // 2. Contrôle de $_FILES pour les erreurs PHP directes (avant même parsing complet)
        $phpFileError = $this->findPhpFileError($_FILES);
        if ($phpFileError !== null) {
            return $this->formatUploadErrorResponse($phpFileError);
        }

        // 3. Contrôle des UploadedFile Symfony attachés à la requête
        $symfonyFileError = $this->findSymfonyFileError($request->files->all());
        if ($symfonyFileError !== null) {
            return $this->formatUploadErrorResponse($symfonyFileError);
        }

        return $next($request);
    }

    /**
     * Recherche récursive d'erreurs d'upload PHP dans le tableau $_FILES brut.
     *
     * @param  array<string, mixed>  $files
     */
    protected function findPhpFileError(array $files): ?int
    {
        foreach ($files as $file) {
            if (is_array($file)) {
                if (isset($file['error'])) {
                    if (is_array($file['error'])) {
                        $nestedError = $this->findPhpFileError(['error' => $file['error']]);
                        if ($nestedError !== null) {
                            return $nestedError;
                        }
                    } elseif ($file['error'] !== UPLOAD_ERR_OK && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                        return (int) $file['error'];
                    }
                } else {
                    $nestedError = $this->findPhpFileError($file);
                    if ($nestedError !== null) {
                        return $nestedError;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Recherche récursive d'erreurs d'upload dans les objets UploadedFile de Symfony.
     *
     * @param  array<string, mixed>  $files
     */
    protected function findSymfonyFileError(array $files): ?int
    {
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                if (! $file->isValid() && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                    return $file->getError();
                }
            } elseif (is_array($file)) {
                $nested = $this->findSymfonyFileError($file);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * Génère une réponse HTTP JSON explicite (413 ou 422) selon le type d'erreur PHP.
     */
    protected function formatUploadErrorResponse(int $errorCode): Response
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => response()->json([
                'message' => 'Le fichier sélectionné dépasse la taille maximale de téléversement autorisée par le serveur.',
                'error' => 'file_too_large',
                'code' => $errorCode === UPLOAD_ERR_INI_SIZE ? 'UPLOAD_ERR_INI_SIZE' : 'UPLOAD_ERR_FORM_SIZE',
                'max_size' => ini_get('upload_max_filesize') ?: null,
                'errors' => [
                    'file' => ['Le fichier sélectionné dépasse la taille maximale de téléversement autorisée.'],
                ],
            ], 413),

            UPLOAD_ERR_PARTIAL => response()->json([
                'message' => 'Le téléversement du fichier a été interrompu.',
                'error' => 'upload_partial',
                'code' => 'UPLOAD_ERR_PARTIAL',
                'errors' => [
                    'file' => ['Le téléversement du fichier n\'a pas pu aboutir complètement.'],
                ],
            ], 422),

            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => response()->json([
                'message' => 'Le serveur ne peut pas enregistrer le fichier temporaire.',
                'error' => 'upload_storage_error',
                'code' => $errorCode === UPLOAD_ERR_NO_TMP_DIR ? 'UPLOAD_ERR_NO_TMP_DIR' : 'UPLOAD_ERR_CANT_WRITE',
            ], 507),

            default => response()->json([
                'message' => 'Une erreur est survenue lors de l\'envoi du fichier.',
                'error' => 'upload_error',
                'code' => 'UPLOAD_ERROR_'.$errorCode,
            ], 422),
        };
    }

    /**
     * Calcule la taille maximale autorisée par post_max_size en octets.
     */
    protected function getPostMaxSize(): int
    {
        $postMaxSize = ini_get('post_max_size');

        if (! $postMaxSize) {
            return 0;
        }

        if (is_numeric($postMaxSize)) {
            return (int) $postMaxSize;
        }

        $metric = strtoupper(substr($postMaxSize, -1));
        $val = (int) $postMaxSize;

        return match ($metric) {
            'K' => $val * 1024,
            'M' => $val * 1048576,
            'G' => $val * 1073741824,
            default => $val,
        };
    }
}
