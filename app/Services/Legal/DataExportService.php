<?php

namespace App\Services\Legal;

use App\Enums\Legal\DataExportStatus;
use App\Models\Legal\DataExportRequest;
use App\Models\Message\Conversation;
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

            // Déterminer les sections demandées (filtrage RGPD)
            $availableSections = ['profile', 'content', 'messages', 'listings', 'payments'];
            $requested = $exportRequest->sections;

            if (is_array($requested) && count($requested) > 0) {
                $normalized = array_map('strtolower', $requested);
                $activeSections = array_values(array_intersect($availableSections, $normalized));
                if (empty($activeSections)) {
                    $activeSections = $availableSections;
                }
            } else {
                $activeSections = $availableSections;
            }

            $collectedData = [];

            // 1. Profil & Identité
            if (in_array('profile', $activeSections, true)) {
                $collectedData['profile'] = $this->extractProfileData($user);
                $collectedData['preferences'] = $this->extractPreferencesData($user);

                file_put_contents(
                    $tempDir.'/profile.json',
                    json_encode($collectedData['profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                file_put_contents(
                    $tempDir.'/preferences_and_privacy.json',
                    json_encode($collectedData['preferences'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }

            // 2. Contenus (Square & Reels)
            if (in_array('content', $activeSections, true)) {
                $collectedData['square_posts'] = $this->extractSquarePosts($user);
                $collectedData['reels'] = $this->extractReels($user);

                file_put_contents(
                    $tempDir.'/square_posts.json',
                    json_encode($collectedData['square_posts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                file_put_contents(
                    $tempDir.'/reels.json',
                    json_encode($collectedData['reels'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }

            // 3. Messages & Conversations
            if (in_array('messages', $activeSections, true)) {
                $collectedData['messages'] = $this->extractMessages($user);

                file_put_contents(
                    $tempDir.'/messages.json',
                    json_encode($collectedData['messages'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }

            // 4. Annonces immobilières
            if (in_array('listings', $activeSections, true)) {
                $collectedData['listings'] = $this->extractListings($user);

                file_put_contents(
                    $tempDir.'/listings.json',
                    json_encode($collectedData['listings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }

            // 5. Paiements, Portefeuille, Abonnements & Campagnes Boost
            if (in_array('payments', $activeSections, true)) {
                $collectedData['transactions'] = $this->extractTransactions($user);
                $collectedData['subscriptions'] = $this->extractSubscriptions($user);
                $collectedData['campaigns'] = $this->extractCampaigns($user);

                file_put_contents(
                    $tempDir.'/transactions.json',
                    json_encode($collectedData['transactions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                file_put_contents(
                    $tempDir.'/subscriptions.json',
                    json_encode($collectedData['subscriptions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                file_put_contents(
                    $tempDir.'/boost_campaigns.json',
                    json_encode($collectedData['campaigns'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
            }

            // Génération de la page HTML lisible si format == html
            if (($exportRequest->format ?? 'json') === 'html') {
                $htmlContent = $this->generateHtmlExport($user, $activeSections, $collectedData);
                file_put_contents($tempDir.'/index.html', $htmlContent);
            }

            // Création de l'archive ZIP
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
                'sections' => $activeSections,
                'format' => $exportRequest->format,
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
            'interests' => $user->interests()->get()->map(fn ($interest) => $interest->name_fr ?? $interest->name_en ?? $interest->id)->values()->all(),
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
    protected function extractMessages(User $user): array
    {
        return Conversation::forUser($user->id)
            ->with(['listing:id,title'])
            ->get()
            ->map(function ($conv) use ($user) {
                $sentMessages = $conv->messages()
                    ->where('sender_id', $user->id)
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(function ($msg) {
                        return [
                            'id' => $msg->id,
                            'type' => $msg->type instanceof \BackedEnum ? $msg->type->value : (string) $msg->type,
                            'body' => $msg->is_retracted ? null : $msg->body,
                            'is_retracted' => (bool) $msg->is_retracted,
                            'attachment_url' => $msg->attachment_url,
                            'created_at' => $msg->created_at?->toIso8601String(),
                        ];
                    })->all();

                return [
                    'conversation_id' => $conv->id,
                    'type' => $conv->type instanceof \BackedEnum ? $conv->type->value : (string) $conv->type,
                    'title' => $conv->title,
                    'listing_id' => $conv->listing_id,
                    'listing_title' => $conv->listing?->title,
                    'created_at' => $conv->created_at?->toIso8601String(),
                    'messages_sent_count' => count($sentMessages),
                    'messages_sent' => $sentMessages,
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

    /**
     * Génère une page HTML autonome et moderne lisible dans n'importe quel navigateur hors-ligne.
     *
     * @param  array<string>  $activeSections
     * @param  array<string, mixed>  $data
     */
    protected function generateHtmlExport(User $user, array $activeSections, array $data): string
    {
        $name = htmlspecialchars($user->name ?? '', ENT_QUOTES, 'UTF-8');
        $handle = htmlspecialchars($user->handle ?? '', ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($user->email ?? '', ENT_QUOTES, 'UTF-8');
        $date = now()->format('d/m/Y H:i');

        $html = '<!DOCTYPE html>'."\n";
        $html .= '<html lang="fr">'."\n<head>\n";
        $html .= '<meta charset="UTF-8">'."\n";
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">'."\n";
        $html .= "<title>Archive de Données Personnelles BANGO - {$name}</title>\n";
        $html .= '<style>
            :root {
                --primary: #0F172A;
                --accent: #2563EB;
                --bg: #F8FAFC;
                --card-bg: #FFFFFF;
                --text: #1E293B;
                --muted: #64748B;
                --border: #E2E8F0;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background-color: var(--bg);
                color: var(--text);
                line-height: 1.5;
                padding: 32px 16px;
            }
            .container {
                max-width: 900px;
                margin: 0 auto;
            }
            header {
                background: var(--card-bg);
                border: 1px solid var(--border);
                border-radius: 12px;
                padding: 28px;
                margin-bottom: 24px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            .brand {
                font-size: 24px;
                font-weight: 800;
                color: var(--accent);
                letter-spacing: -0.5px;
                margin-bottom: 8px;
            }
            h1 { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
            .meta { color: var(--muted); font-size: 14px; }
            .card {
                background: var(--card-bg);
                border: 1px solid var(--border);
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 24px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            h2 {
                font-size: 18px;
                font-weight: 700;
                color: var(--primary);
                border-bottom: 2px solid var(--border);
                padding-bottom: 10px;
                margin-bottom: 16px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 8px;
                font-size: 14px;
            }
            th, td {
                padding: 10px 12px;
                text-align: left;
                border-bottom: 1px solid var(--border);
            }
            th { background: #F1F5F9; color: var(--muted); font-weight: 600; }
            .item-block {
                background: #F8FAFC;
                border: 1px solid var(--border);
                border-radius: 8px;
                padding: 14px;
                margin-bottom: 12px;
            }
            .item-title { font-weight: 600; font-size: 15px; margin-bottom: 4px; }
            .item-meta { font-size: 13px; color: var(--muted); }
            .badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
                background: #E2E8F0;
                color: #334155;
            }
            .empty { color: var(--muted); font-style: italic; font-size: 14px; }
        </style>'."\n";
        $html .= "</head>\n<body>\n";
        $html .= '<div class="container">'."\n";

        // En-tête
        $html .= '<header>'."\n";
        $html .= '  <div class="brand">BANGO</div>'."\n";
        $html .= "  <h1>Archive de vos données personnelles (RGPD)</h1>\n";
        $html .= "  <div class=\"meta\">Titulaire : <strong>{$name}</strong> (@{$handle}) &bull; {$email} &bull; Générée le {$date}</div>\n";
        $html .= '</header>'."\n";

        // 1. Profil
        if (in_array('profile', $activeSections, true)) {
            $p = $data['profile'] ?? [];
            $html .= '<section class="card">'."\n";
            $html .= '  <h2>1. Profil & Identité</h2>'."\n";
            $html .= '  <table>'."\n";
            $html .= '    <tr><th style="width: 30%;">Champ</th><th>Valeur</th></tr>'."\n";
            foreach ($p as $key => $val) {
                $valStr = is_bool($val) ? ($val ? 'Oui' : 'Non') : (is_array($val) ? json_encode($val) : (string) $val);
                $html .= '    <tr><td>'.htmlspecialchars((string) $key).'</td><td>'.htmlspecialchars($valStr).'</td></tr>'."\n";
            }
            $html .= '  </table>'."\n";
            $html .= '</section>'."\n";
        }

        // 2. Contenus
        if (in_array('content', $activeSections, true)) {
            $posts = $data['square_posts'] ?? [];
            $reels = $data['reels'] ?? [];
            $html .= '<section class="card">'."\n";
            $html .= '  <h2>2. Vos Publications (Square & Reels)</h2>'."\n";
            $html .= '  <h3 style="font-size:15px; margin: 12px 0 8px;">Posts Square ('.count($posts).')</h3>'."\n";
            if (empty($posts)) {
                $html .= '  <p class="empty">Aucun post Square.</p>'."\n";
            } else {
                foreach ($posts as $post) {
                    $body = htmlspecialchars($post['body'] ?? '', ENT_QUOTES, 'UTF-8');
                    $pDate = htmlspecialchars($post['created_at'] ?? '', ENT_QUOTES, 'UTF-8');
                    $likes = (int) ($post['likes_count'] ?? 0);
                    $comments = (int) ($post['comments_count'] ?? 0);
                    $html .= "  <div class=\"item-block\"><div class=\"item-title\">{$body}</div><div class=\"item-meta\">Posté le {$pDate} &bull; {$likes} likes &bull; {$comments} commentaires</div></div>\n";
                }
            }

            $html .= '  <h3 style="font-size:15px; margin: 16px 0 8px;">Reels ('.count($reels).')</h3>'."\n";
            if (empty($reels)) {
                $html .= '  <p class="empty">Aucun Reel publié.</p>'."\n";
            } else {
                foreach ($reels as $reel) {
                    $caption = htmlspecialchars($reel['caption'] ?? '', ENT_QUOTES, 'UTF-8');
                    $rDate = htmlspecialchars($reel['created_at'] ?? '', ENT_QUOTES, 'UTF-8');
                    $views = (int) ($reel['views_count'] ?? 0);
                    $html .= "  <div class=\"item-block\"><div class=\"item-title\">{$caption}</div><div class=\"item-meta\">Publié le {$rDate} &bull; {$views} vues</div></div>\n";
                }
            }
            $html .= '</section>'."\n";
        }

        // 3. Messages
        if (in_array('messages', $activeSections, true)) {
            $convs = $data['messages'] ?? [];
            $html .= '<section class="card">'."\n";
            $html .= '  <h2>3. Messages & Conversations ('.count($convs).')</h2>'."\n";
            if (empty($convs)) {
                $html .= '  <p class="empty">Aucune conversation enregistrée.</p>'."\n";
            } else {
                foreach ($convs as $conv) {
                    $cTitle = htmlspecialchars($conv['title'] ?? 'Conversation '.$conv['type'], ENT_QUOTES, 'UTF-8');
                    $listingInfo = ! empty($conv['listing_title']) ? ' &bull; Annonce : '.htmlspecialchars($conv['listing_title'], ENT_QUOTES, 'UTF-8') : '';
                    $sent = $conv['messages_sent'] ?? [];
                    $html .= "  <div class=\"item-block\">\n";
                    $html .= "    <div class=\"item-title\">{$cTitle}{$listingInfo}</div>\n";
                    $html .= '    <div class="item-meta">Messages envoyés par vous : '.count($sent)."</div>\n";
                    if (! empty($sent)) {
                        $html .= "    <ul style=\"margin: 8px 0 0 16px; font-size: 13px;\">\n";
                        foreach ($sent as $msg) {
                            $mBody = htmlspecialchars($msg['body'] ?? '[Pièce jointe / média]', ENT_QUOTES, 'UTF-8');
                            $mDate = htmlspecialchars($msg['created_at'] ?? '', ENT_QUOTES, 'UTF-8');
                            $html .= "      <li style=\"margin-bottom: 4px;\"><strong>{$mDate}</strong> : {$mBody}</li>\n";
                        }
                        $html .= "    </ul>\n";
                    }
                    $html .= "  </div>\n";
                }
            }
            $html .= '</section>'."\n";
        }

        // 4. Annonces
        if (in_array('listings', $activeSections, true)) {
            $listings = $data['listings'] ?? [];
            $html .= '<section class="card">'."\n";
            $html .= '  <h2>4. Vos Annonces Immobilières ('.count($listings).')</h2>'."\n";
            if (empty($listings)) {
                $html .= '  <p class="empty">Aucune annonce enregistrée.</p>'."\n";
            } else {
                foreach ($listings as $lst) {
                    $lTitle = htmlspecialchars($lst['title'] ?? '', ENT_QUOTES, 'UTF-8');
                    $lPrice = htmlspecialchars(number_format((float) ($lst['price'] ?? 0), 0, ',', ' ').' '.($lst['currency'] ?? 'XAF'), ENT_QUOTES, 'UTF-8');
                    $lCity = htmlspecialchars($lst['city'] ?? '', ENT_QUOTES, 'UTF-8');
                    $lStatus = htmlspecialchars($lst['status'] ?? '', ENT_QUOTES, 'UTF-8');
                    $html .= "  <div class=\"item-block\"><div class=\"item-title\">{$lTitle} &bull; <span class=\"badge\">{$lStatus}</span></div><div class=\"item-meta\">{$lPrice} &bull; {$lCity}</div></div>\n";
                }
            }
            $html .= '</section>'."\n";
        }

        // 5. Paiements
        if (in_array('payments', $activeSections, true)) {
            $tx = $data['transactions']['credit_transactions'] ?? [];
            $subs = $data['subscriptions'] ?? [];
            $html .= '<section class="card">'."\n";
            $html .= '  <h2>5. Paiements, Abonnements & Crédits</h2>'."\n";
            $balance = htmlspecialchars($data['transactions']['credit_wallet_balance'] ?? '0.00', ENT_QUOTES, 'UTF-8');
            $html .= "  <p style=\"margin-bottom: 12px;\"><strong>Solde du portefeuille de crédits :</strong> {$balance} crédits</p>\n";

            $html .= '  <h3 style="font-size:15px; margin: 12px 0 8px;">Abonnements & Badges</h3>'."\n";
            if (empty($subs)) {
                $html .= '  <p class="empty">Aucun abonnement actif ou passé.</p>'."\n";
            } else {
                foreach ($subs as $sub) {
                    $sBadge = htmlspecialchars($sub['badge_type'] ?? 'badge', ENT_QUOTES, 'UTF-8');
                    $sState = htmlspecialchars($sub['state'] ?? '', ENT_QUOTES, 'UTF-8');
                    $sAmount = htmlspecialchars($sub['amount'].' '.($sub['currency'] ?? 'XAF'), ENT_QUOTES, 'UTF-8');
                    $html .= "  <div class=\"item-block\"><div class=\"item-title\">Badge {$sBadge} &bull; <span class=\"badge\">{$sState}</span></div><div class=\"item-meta\">Montant : {$sAmount} &bull; Cadence : {$sub['cadence']}</div></div>\n";
                }
            }

            $html .= '  <h3 style="font-size:15px; margin: 16px 0 8px;">Historique des Transactions ('.count($tx).')</h3>'."\n";
            if (empty($tx)) {
                $html .= '  <p class="empty">Aucune transaction enregistrée.</p>'."\n";
            } else {
                $html .= '  <table><tr><th>Date</th><th>Description</th><th>Montant</th></tr>'."\n";
                foreach ($tx as $t) {
                    $tDate = htmlspecialchars($t['created_at'] ?? '', ENT_QUOTES, 'UTF-8');
                    $tDesc = htmlspecialchars($t['description'] ?? $t['type'], ENT_QUOTES, 'UTF-8');
                    $tAmt = htmlspecialchars((string) $t['amount'], ENT_QUOTES, 'UTF-8');
                    $html .= "    <tr><td>{$tDate}</td><td>{$tDesc}</td><td>{$tAmt}</td></tr>\n";
                }
                $html .= '  </table>'."\n";
            }
            $html .= '</section>'."\n";
        }

        $html .= "</div>\n</body>\n</html>";

        return $html;
    }
}
