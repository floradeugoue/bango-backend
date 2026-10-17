<?php

namespace App\Services\Admin;

use App\Models\Admin\AdminAuditLog;
use App\Models\Square\Hashtag;
use App\Models\Support\HashtagExclusion;
use App\Models\User;
use App\Services\Search\TrendsService;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class ModerationTrendService
{
    public function __construct(
        protected TrendsService $trendsService
    ) {}

    /**
     * Récupère les tendances globales en annotant les hashtags exclus par la modération.
     *
     * @return array<string, mixed>
     */
    public function getTrendsWithExclusions(?User $viewer = null): array
    {
        $rawTrends = $this->trendsService->getTrends($viewer);

        $activeExclusions = HashtagExclusion::query()
            ->where('is_active', true)
            ->with(['excluder:id,name,email'])
            ->get();

        $excludedNames = $activeExclusions->pluck('hashtag_name')->map(fn ($n) => mb_strtolower($n))->all();

        // Annotation des hashtags
        $annotatedHashtags = $rawTrends['top_hashtags']->map(function ($ht) use ($excludedNames) {
            $normalized = mb_strtolower($ht->normalized_name ?? $ht->name);
            $ht->is_excluded = in_array($normalized, $excludedNames, true);

            return $ht;
        });

        return [
            'top_hashtags' => $annotatedHashtags,
            'trending_sounds' => $rawTrends['trending_sounds'],
            'emerging_creators' => $rawTrends['emerging_creators'],
            'active_exclusions' => $activeExclusions,
        ];
    }

    /**
     * Exclut un hashtag du moteur de tendances et des surfaces de recommandation.
     */
    public function excludeHashtag(string $tag, string $reason, User $operator): HashtagExclusion
    {
        $normalized = Hashtag::normalize($tag);
        if (empty($normalized)) {
            throw new InvalidArgumentException('Le nom du hashtag ne peut pas être vide.');
        }

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('Un motif est obligatoire pour prononcer une exclusion de tendance.');
        }

        $existing = HashtagExclusion::where('hashtag_name', $normalized)
            ->where('is_active', true)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException("Le hashtag '#{$normalized}' fait déjà l'objet d'une exclusion active.");
        }

        $hashtag = Hashtag::where('normalized_name', $normalized)->first();

        $exclusion = HashtagExclusion::create([
            'hashtag_id' => $hashtag?->id,
            'hashtag_name' => $normalized,
            'reason' => $reason,
            'excluded_by' => $operator->id,
            'is_active' => true,
        ]);

        AdminAuditLog::logEvent('moderation.trends.excluded', $operator, request()->ip(), request()->userAgent(), [
            'hashtag' => $normalized,
            'reason' => $reason,
        ]);

        return $exclusion;
    }

    /**
     * Restaure un hashtag précédemment exclu des tendances.
     */
    public function restoreHashtag(string $tag, User $operator): HashtagExclusion
    {
        $normalized = Hashtag::normalize($tag);

        /** @var HashtagExclusion $exclusion */
        $exclusion = HashtagExclusion::where('hashtag_name', $normalized)
            ->where('is_active', true)
            ->firstOrFail();

        $exclusion->is_active = false;
        $exclusion->restored_by = $operator->id;
        $exclusion->restored_at = now();
        $exclusion->save();

        AdminAuditLog::logEvent('moderation.trends.restored', $operator, request()->ip(), request()->userAgent(), [
            'hashtag' => $normalized,
        ]);

        return $exclusion;
    }

    /**
     * Liste paginée des exclusions de hashtags.
     */
    public function listExclusions(?bool $activeOnly = null): Collection
    {
        $query = HashtagExclusion::query()->with(['excluder:id,name,email', 'restorer:id,name,email']);

        if ($activeOnly !== null) {
            $query->where('is_active', $activeOnly);
        }

        return $query->orderByDesc('created_at')->get();
    }
}
