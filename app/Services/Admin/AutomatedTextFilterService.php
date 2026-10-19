<?php

namespace App\Services\Admin;

use App\Models\Support\ModerationFilterLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AutomatedTextFilterService
{
    /**
     * Motifs d'expression régulière pour la détection automatique de contenus suspects.
     */
    protected const PATTERNS = [
        'phone_leak' => '/(\+?237|00237)?\s*[623]\s*[0-9](\s*[0-9]){7}/i',
        'off_platform' => '/(monetbil\s*direct|orange\s*money\s*direct|mtn\s*momo\s*direct|western\s*union|moneygram|virement\s*direct|envoi\s*cash|contacte[- ]moi\s*sur\s*whatsapp|whatsapp\s*au\s*:\s*[0-9]+)/i',
        'scam' => '/(gain\s*de\s*loterie|doubler?\s*vos\s*gains|investis?\s*et\s*recois|cliquez?\s*ici\s*pour\s*gagner|mot\s*de\s*passe\s*bancaire|code\s*secret\s*momo)/i',
    ];

    /**
     * Analyse un texte et enregistre un événement de filtre si un motif suspect est détecté.
     *
     * @return array<string, mixed>|null
     */
    public function scanText(string $text, string $contentType, string|int $contentId, ?User $author = null): ?array
    {
        $detectedCategory = null;
        $matchedPattern = null;

        foreach (self::PATTERNS as $category => $regex) {
            if (preg_match($regex, $text, $matches)) {
                $detectedCategory = $category;
                $matchedPattern = $matches[0];
                break;
            }
        }

        if (! $detectedCategory) {
            return null;
        }

        $log = ModerationFilterLog::create([
            'user_id' => $author?->id,
            'content_type' => $contentType,
            'content_id' => (string) $contentId,
            'category' => $detectedCategory,
            'detected_pattern' => $matchedPattern,
            'original_text' => $text,
            'action_taken' => 'flagged',
            'created_at' => now(),
        ]);

        return [
            'flagged' => true,
            'category' => $detectedCategory,
            'pattern' => $matchedPattern,
            'log_id' => $log->id,
        ];
    }

    /**
     * Récupère la liste paginée des déclenchements du filtre textuel automatique.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getFilterLogs(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = ModerationFilterLog::query()->with(['user:id,name,email,avatar_url']);

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['action_taken'])) {
            $query->where('action_taken', $filters['action_taken']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', Carbon::parse($filters['date_from'])->toDateString());
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', Carbon::parse($filters['date_to'])->toDateString());
        }

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('detected_pattern', 'LIKE', "%{$search}%")
                    ->orWhere('original_text', 'LIKE', "%{$search}%")
                    ->orWhere('content_id', 'LIKE', "%{$search}%");
            });
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
