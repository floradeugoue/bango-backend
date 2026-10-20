<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelStatus;
use App\Jobs\Reel\ProcessReelVideoJob;
use App\Models\Reel\Reel;
use App\Models\User;
use App\Services\Admin\AutomatedTextFilterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReelPublishService
{
    public function __construct(protected ReelDraftService $draftService) {}

    /**
     * Lance la publication d'un Reel (asynchrone avec job Laravel Queue).
     * Protégé contre les doubles publications concurrentes et valide les autorisations de sons.
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(array $data, User $user): Reel
    {
        $draftId = $data['draft_id'] ?? null;

        return DB::transaction(function () use ($draftId, $data, $user) {
            $reel = null;

            if ($draftId) {
                $reel = Reel::where('id', $draftId)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $reel) {
                    throw ValidationException::withMessages([
                        'draft_id' => ['Le brouillon spécifié est introuvable ou ne vous appartient pas.'],
                    ]);
                }

                // Prévention des doubles publications
                if ($reel->status === ReelStatus::Processing || $reel->status === ReelStatus::Ready) {
                    throw ValidationException::withMessages([
                        'draft_id' => ['Ce Reel est déjà en cours de traitement ou a déjà été publié.'],
                    ]);
                }
            }

            if (! $reel) {
                $reel = new Reel;
                $reel->id = (string) Str::uuid();
                $reel->user_id = $user->id;
            }

            // Vérification de l'éligibilité du son (verrouillage / disponibilité)
            $soundId = $data['sound_id'] ?? $reel->sound_id;
            if ($soundId) {
                $this->draftService->validateSoundEligibility($soundId, $user);
            }

            $composition = $data['composition'] ?? $reel->composition;
            $reel->composition = $composition;

            // Déduction automatique de l'URL vidéo principale si issue d'une composition de clips caméra
            $videoUrl = $data['video_url'] ?? $reel->video_url;
            if (empty($videoUrl) && ! empty($composition['clips']) && is_array($composition['clips'])) {
                $videoUrl = $composition['clips'][0]['uri'] ?? null;
            }
            $reel->video_url = $videoUrl;

            // Déduction de la miniature depuis coverUri si non fournie explicitement
            $thumbnailUrl = $data['thumbnail_url'] ?? $reel->thumbnail_url;
            if (empty($thumbnailUrl) && ! empty($composition['coverUri'])) {
                $thumbnailUrl = $composition['coverUri'];
            }
            $reel->thumbnail_url = $thumbnailUrl;

            // Déduction de la durée totale en secondes à partir des clips caméra si non spécifiée
            $duration = isset($data['duration']) ? (int) $data['duration'] : $reel->duration;
            if (empty($duration) && ! empty($composition['clips']) && is_array($composition['clips'])) {
                $totalMs = 0;
                foreach ($composition['clips'] as $clip) {
                    $totalMs += (int) ($clip['durationMs'] ?? 0);
                }
                if ($totalMs > 0) {
                    $duration = (int) max(1, round($totalMs / 1000));
                }
            }
            $reel->duration = $duration;

            $reel->caption = $data['caption'] ?? $reel->caption;
            $reel->topic = $data['topic'] ?? ($reel->topic ?: 'neighbourhood');
            $reel->sound_id = $soundId;
            $reel->allow_download = isset($data['allow_download']) ? (bool) $data['allow_download'] : ($reel->allow_download ?? true);
            $reel->comments_closed = isset($data['comments_closed']) ? (bool) $data['comments_closed'] : ($reel->comments_closed ?? false);
            $reel->subtitles = $data['subtitles'] ?? $reel->subtitles;
            $reel->subtitles_locale = $data['subtitles_locale'] ?? ($reel->subtitles_locale ?: 'fr');
            $reel->listing_id = $data['listing_id'] ?? $reel->listing_id;

            $reel->status = ReelStatus::Processing;
            $reel->error_message = null;
            $reel->save();

            // Point 19 : Déclenchement du filtre textuel automatique sur la légende du Reel
            if (! empty($reel->caption)) {
                app(AutomatedTextFilterService::class)
                    ->scanText($reel->caption, 'reel', $reel->id, $user);
            }

            // Dispatch du job de traitement vidéo asynchrone
            ProcessReelVideoJob::dispatch($reel);

            return $reel;
        });
    }
}
