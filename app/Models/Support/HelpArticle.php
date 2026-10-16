<?php

namespace App\Models\Support;

use App\Enums\Support\HelpArticleCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class HelpArticle extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'category',
        'title',
        'content_markdown',
        'helpful_count',
        'unhelpful_count',
        'views_count',
        'is_published',
        'published_at',
        'locale',
        'order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => HelpArticleCategory::class,
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'helpful_count' => 'integer',
            'unhelpful_count' => 'integer',
            'views_count' => 'integer',
            'order' => 'integer',
        ];
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(HelpArticleFeedback::class);
    }

    /**
     * Scope aux articles publiés.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Scope par catégorie.
     */
    public function scopeCategory(Builder $query, ?string $category): Builder
    {
        if (empty($category)) {
            return $query;
        }

        return $query->where('category', $category);
    }

    /**
     * Scope par locale.
     */
    public function scopeLocale(Builder $query, ?string $locale): Builder
    {
        if (empty($locale)) {
            return $query;
        }

        return $query->where('locale', $locale);
    }

    /**
     * Recherche textuelle dans le titre et le contenu Markdown.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL Fulltext Search ou ILIKE
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'ILIKE', "%{$term}%")
                    ->orWhere('content_markdown', 'ILIKE', "%{$term}%");
            });
        } else {
            // Fallback SQLite / MySQL
            $query->where(function (Builder $q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('content_markdown', 'like', "%{$term}%");
            });
        }

        return $query;
    }
}
