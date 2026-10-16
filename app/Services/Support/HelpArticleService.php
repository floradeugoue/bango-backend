<?php

namespace App\Services\Support;

use App\Models\Support\HelpArticle;
use App\Models\Support\HelpArticleFeedback;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class HelpArticleService
{
    /**
     * Liste et recherche paginée des articles d'aide publiés.
     *
     * @param  array{q?: ?string, category?: ?string, locale?: ?string}  $filters
     */
    public function listArticles(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 50);

        $query = HelpArticle::query()
            ->published()
            ->search($filters['q'] ?? null)
            ->category($filters['category'] ?? null)
            ->locale($filters['locale'] ?? null)
            ->orderBy('order', 'asc')
            ->orderBy('id', 'asc');

        return $query->paginate($perPage);
    }

    /**
     * Consultation d'un article par son slug et incrément des vues.
     */
    public function findBySlug(string $slug, ?string $locale = null): ?HelpArticle
    {
        $query = HelpArticle::query()
            ->published()
            ->where('slug', $slug);

        if ($locale) {
            $query->locale($locale);
        }

        $article = $query->first();

        if ($article) {
            $article->increment('views_count');
        }

        return $article;
    }

    /**
     * Enregistre ou met à jour le vote d'utilité de manière atomique et idempotente.
     *
     * @return array{helpful: bool, helpful_count: int, unhelpful_count: int, changed: bool}
     */
    public function recordFeedback(
        HelpArticle $article,
        bool $isHelpful,
        ?User $user = null,
        ?string $ipAddress = null,
        ?string $sessionId = null
    ): array {
        return DB::transaction(function () use ($article, $isHelpful, $user, $ipAddress, $sessionId) {
            // Verrouiller la ligne de l'article pour synchroniser les compteurs
            $article = HelpArticle::where('id', $article->id)->lockForUpdate()->firstOrFail();

            $query = HelpArticleFeedback::where('help_article_id', $article->id);

            if ($user) {
                $query->where('user_id', $user->id);
            } elseif ($sessionId) {
                $query->where('session_id', $sessionId);
            } elseif ($ipAddress) {
                $query->where('ip_address', $ipAddress);
            } else {
                // Impossible de dédupliquer sans identifiant
                return [
                    'helpful' => $isHelpful,
                    'helpful_count' => $article->helpful_count,
                    'unhelpful_count' => $article->unhelpful_count,
                    'changed' => false,
                ];
            }

            $existing = $query->first();

            if ($existing) {
                if ($existing->is_helpful === $isHelpful) {
                    // Même vote : idempotent, aucun changement de compteur
                    return [
                        'helpful' => $isHelpful,
                        'helpful_count' => $article->helpful_count,
                        'unhelpful_count' => $article->unhelpful_count,
                        'changed' => false,
                    ];
                }

                // Modification du vote : bascule entre helpful et unhelpful
                $existing->is_helpful = $isHelpful;
                $existing->save();

                if ($isHelpful) {
                    $article->helpful_count++;
                    $article->unhelpful_count = max(0, $article->unhelpful_count - 1);
                } else {
                    $article->helpful_count = max(0, $article->helpful_count - 1);
                    $article->unhelpful_count++;
                }

                $article->save();

                return [
                    'helpful' => $isHelpful,
                    'helpful_count' => $article->helpful_count,
                    'unhelpful_count' => $article->unhelpful_count,
                    'changed' => true,
                ];
            }

            // Nouveau vote
            HelpArticleFeedback::create([
                'help_article_id' => $article->id,
                'user_id' => $user?->id,
                'ip_address' => $ipAddress,
                'session_id' => $sessionId,
                'is_helpful' => $isHelpful,
            ]);

            if ($isHelpful) {
                $article->helpful_count++;
            } else {
                $article->unhelpful_count++;
            }

            $article->save();

            return [
                'helpful' => $isHelpful,
                'helpful_count' => $article->helpful_count,
                'unhelpful_count' => $article->unhelpful_count,
                'changed' => true,
            ];
        });
    }
}
