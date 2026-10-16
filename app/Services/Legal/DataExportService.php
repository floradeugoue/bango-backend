<?php

namespace App\Services\Legal;

use App\Enums\Legal\DataExportStatus;
use App\Models\Legal\DataExportRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

class DataExportService
{
    /**
     * Génère l'archive ZIP des données personnelles de manière sécurisée.
     */
    public function generateExport(DataExportRequest $exportRequest): void
    {
        $exportRequest->update(['status' => DataExportStatus::Processing]);

        $user = $exportRequest->user;
        $disk = Storage::disk($exportRequest->disk);

        try {
            $tempDir = storage_path('app/temp_exports/'.$exportRequest->id);
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // 1. Profil & Identité (sans secrets)
            file_put_contents($tempDir.'/profile.json', json_encode($this->extractProfileData($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 2. Préférences & Confidentialité
            file_put_contents($tempDir.'/preferences_and_privacy.json', json_encode($this->extractPreferencesData($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 3. Publications Square
            file_put_contents($tempDir.'/square_posts.json', json_encode($this->extractSquarePosts($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 4. Reels
            file_put_contents($tempDir.'/reels.json', json_encode($this->extractReels($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 5. Annonces immobilières
            file_put_contents($tempDir.'/listings.json', json_encode($this->extractListings($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 6. Campagnes Publicitaires Boost
            file_put_contents($tempDir.'/boost_campaigns.json', json_encode($this->extractCampaigns($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 7. Transactions Financières & Crédits
            file_put_contents($tempDir.'/transactions.json', json_encode($this->extractTransactions($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 8. Abonnements & Badges
            file_put_contents($tempDir.'/subscriptions.json', json_encode($this->extractSubscriptions($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 9. Création de l'archive ZIP
            $zipRelativePath = 'exports/'.$user->id.'/bango_export_'.$exportRequest->id.'.zip';
            $zipAbsolutePath = storage_path('app/'.$zipRelativePath);

            $zipDir = dirname($zipAbsolutePath);
            if (! is_dir($zipDir)) {
                mkdir($zipDir, 0755, true);
            }

            $zip = new ZipArchive;
            if ($zip->open($zipAbsolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $files = scandir($tempDir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $zip->addFile($tempDir.'/'.$file, $file);
                    }
                }
                $zip->close();
            }

            // Nettoyage du dossier temporaire
            foreach (scandir($tempDir) as $file) {
                if ($file !== '.' && $file !== '..') {
                    unlink($tempDir.'/'.$file);
                }
            }
            rmdir($tempDir);

            $fileSize = file_exists($zipAbsolutePath) ? filesize($zipAbsolutePath) : 0;

            $exportRequest->update([
                'status' => DataExportStatus::Ready,
                'file_path' => $zipRelativePath,
                'file_size_bytes' => $fileSize,
                'expires_at' => now()->addHours(48),
                'completed_at' => now(),
            ]);

            Log::info('Archive RGPD générée avec succès', [
                'user_id' => $user->id,
                'request_id' => $exportRequest->id,
                'file_size' => $fileSize,
            ]);

        } catch (Throwable $e) {
            Log::error('Échec de la génération de l\'export RGPD', [
                'user_id' => $user->id,
                'request_id' => $exportRequest->id,
                'error' => $e->getMessage(),
            ]);

            $exportRequest->update([
                'status' => DataExportStatus::Failed,
                'error_message' => 'Une erreur est survenue lors de la création de l\'archive.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractProfileData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'display_name' => $user->display_name,
            'handle' => $user->handle,
            'email' => $user->email,
            'phone' => $user->phone,
            'bio' => $user->bio,
            'birth_date' => $user->birth_date?->toDateString(),
            'gender' => $user->gender?->value ?? $user->gender,
            'account_type' => $user->account_type?->value ?? $user->account_type,
            'badge' => $user->badge,
            'is_verified' => (bool) $user->is_verified,
            'locale' => $user->locale,
            'city' => $user->city,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractPreferencesData(User $user): array
    {
        return [
            'is_private' => (bool) $user->is_private,
            'privacy_settings' => $user->privacy_settings,
            'interests' => $user->interests()->pluck('name')->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractSquarePosts(User $user): array
    {
        return $user->posts()->get()->map(function ($post) {
            return [
                'id' => $post->id,
                'body' => $post->body,
                'likes_count' => $post->likes_count,
                'comments_count' => $post->comments_count,
                'created_at' => $post->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractReels(User $user): array
    {
        return $user->reels()->get()->map(function ($reel) {
            return [
                'id' => $reel->id,
                'caption' => $reel->caption,
                'duration_seconds' => $reel->duration_seconds,
                'views_count' => $reel->views_count,
                'likes_count' => $reel->likes_count,
                'created_at' => $reel->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractListings(User $user): array
    {
        return $user->listings()->get()->map(function ($listing) {
            return [
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'price' => $listing->price,
                'currency' => $listing->currency,
                'status' => $listing->status,
                'city' => $listing->city,
                'created_at' => $listing->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractCampaigns(User $user): array
    {
        return $user->campaigns()->get()->map(function ($campaign) {
            return [
                'id' => $campaign->id,
                'objective' => $campaign->objective->value,
                'budget_amount' => $campaign->budget_amount,
                'budget_currency' => $campaign->budget_currency,
                'status' => $campaign->status->value,
                'spent_amount' => $campaign->spent_amount,
                'start_at' => $campaign->start_at?->toIso8601String(),
                'end_at' => $campaign->end_at?->toIso8601String(),
                'created_at' => $campaign->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractTransactions(User $user): array
    {
        $creditTransactions = $user->creditWallet?->transactions()->get()->map(function ($tx) {
            return [
                'id' => $tx->id,
                'type' => $tx->type->value,
                'amount' => $tx->amount,
                'balance_after' => $tx->balance_after,
                'description' => $tx->description,
                'created_at' => $tx->created_at?->toIso8601String(),
            ];
        })->all() ?? [];

        return [
            'credit_wallet_balance' => $user->creditWallet?->balance ?? '0.00',
            'credit_transactions' => $creditTransactions,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractSubscriptions(User $user): array
    {
        return $user->subscriptions()->get()->map(function ($sub) {
            return [
                'id' => $sub->id,
                'badge_type' => $sub->badge_type,
                'cadence' => $sub->cadence,
                'amount' => $sub->amount,
                'currency' => $sub->currency,
                'state' => $sub->state,
                'current_period_start' => $sub->current_period_start?->toIso8601String(),
                'current_period_end' => $sub->current_period_end?->toIso8601String(),
            ];
        })->all();
    }
}
