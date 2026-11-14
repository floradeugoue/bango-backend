<?php

namespace App\Models\Support;

use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Listing\Listing;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'reporter_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'details',
        'status',
        'assigned_to',
        'priority',
        'escalated_to',
        'escalation_reason',
        'action_taken',
        'resolved_at',
        'resolved_by',
        'metadata',
    ];

    protected $appends = [
        'target',
        'target_type',
        'available_actions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'metadata' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Report $report) {
            if (empty($report->reference)) {
                $year = date('Y');
                $random = strtoupper(Str::random(6));
                $report->reference = "MOD-{$year}-{$random}";
            }
        });
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function escalatedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ModerationDecision::class)->orderByDesc('created_at');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(ModerationEvidence::class)->orderByDesc('created_at');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Résout la classe Eloquent à partir de l'alias d'API.
     */
    public static function resolveReportableClass(string $type): ?string
    {
        return match (strtolower($type)) {
            'post', 'posts' => Post::class,
            'post_comment', 'post_comments' => PostComment::class,
            'reel', 'reels' => Reel::class,
            'reel_comment', 'reel_comments' => ReelComment::class,
            'listing', 'listings' => Listing::class,
            'message', 'messages' => Message::class,
            'user', 'users', 'profile', 'account', 'accounts' => User::class,
            'page', 'pages' => Page::class,
            default => class_exists($type) ? $type : null,
        };
    }

    /**
     * Résout l'alias court d'API à partir de la classe Eloquent.
     */
    public static function resolveShortType(string $className): string
    {
        return match ($className) {
            Post::class, 'post', 'posts' => 'post',
            PostComment::class, 'post_comment', 'post_comments' => 'post_comment',
            Reel::class, 'reel', 'reels' => 'reel',
            ReelComment::class, 'reel_comment', 'reel_comments' => 'reel_comment',
            Listing::class, 'listing', 'listings' => 'listing',
            Message::class, 'message', 'messages' => 'message',
            User::class, 'user', 'users', 'account', 'accounts', 'profile' => 'user',
            Page::class, 'page', 'pages' => 'page',
            default => class_basename($className),
        };
    }

    /**
     * Attribut target exposé automatiquement dans chaque élément de la file.
     * { type, id, display_name, handle, avatar_url, city, badge, excerpt, thumbnail_url }
     *
     * @return array<string, mixed>
     */
    public function getTargetAttribute(): array
    {
        return $this->resolveTargetData();
    }

    /**
     * Accesseur metadata assurant que target_label prend le display_name de la cible si disponible.
     *
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    public function getMetadataAttribute($value): array
    {
        $meta = is_string($value) ? json_decode($value, true) : (is_array($value) ? $value : []);

        $target = $this->target;
        if (in_array($target['type'] ?? null, ['account', 'page'], true) && ! empty($target['display_name'])) {
            $meta['target_label'] = $target['display_name'];
        } elseif (empty($meta['target_label']) && ! empty($target['excerpt'])) {
            $meta['target_label'] = $target['excerpt'];
        }

        if (! empty($target['thumbnail_url']) && empty($meta['target_thumbnail_url'])) {
            $meta['target_thumbnail_url'] = $target['thumbnail_url'];
        }
        if (! empty($target['excerpt']) && empty($meta['target_excerpt'])) {
            $meta['target_excerpt'] = $target['excerpt'];
        }

        return $meta;
    }

    /**
     * Résout la structure de données complète de la cible signalée.
     *
     * @return array<string, mixed>
     */
    public function resolveTargetData(): array
    {
        $shortType = static::resolveShortType($this->reportable_type);
        $targetId = (string) $this->reportable_id;

        $target = $this->relationLoaded('reportable')
            ? $this->reportable
            : null;

        if (! $target) {
            $class = static::resolveReportableClass($this->reportable_type);
            if ($class && class_exists($class)) {
                $target = $class::find($this->reportable_id);
            }
        }

        $type = match ($shortType) {
            'user' => 'account',
            'post_comment', 'reel_comment' => 'comment',
            default => $shortType,
        };

        $displayName = null;
        $handle = null;
        $avatarUrl = null;
        $city = null;
        $badge = null;
        $excerpt = null;
        $thumbnailUrl = null;

        if ($target instanceof User) {
            $displayName = $target->display_name ?: $target->name;
            $handle = $target->handle;
            $avatarUrl = $target->avatar_url;
            $city = $target->city;
            $badge = $target->badge;
            $excerpt = $target->bio;
            $thumbnailUrl = $target->avatar_url;
        } elseif ($target instanceof Post) {
            $author = $target->relationLoaded('user') ? $target->user : $target->user()->first();
            $displayName = $author?->display_name ?: $author?->name;
            $handle = $author?->handle;
            $avatarUrl = $author?->avatar_url;
            $city = $target->place_city ?? $author?->city;
            $badge = $author?->badge;
            $excerpt = mb_substr(strip_tags((string) ($target->body ?? '')), 0, 150);

            $media = $target->relationLoaded('media') ? $target->media : $target->media()->get();
            $firstMedia = $media->first();
            $thumbnailUrl = $firstMedia?->poster_url ?? $firstMedia?->thumbnail_url ?? $firstMedia?->media_url;
        } elseif ($target instanceof Reel) {
            $author = $target->relationLoaded('user') ? $target->user : $target->user()->first();
            $displayName = $author?->display_name ?: $author?->name;
            $handle = $author?->handle;
            $avatarUrl = $author?->avatar_url;
            $city = $author?->city;
            $badge = $author?->badge;
            $excerpt = mb_substr(strip_tags((string) ($target->caption ?? '')), 0, 150);
            $thumbnailUrl = $target->thumbnail_url ?? $target->video_url;
        } elseif ($target instanceof Listing) {
            $owner = $target->relationLoaded('owner') ? $target->owner : ($target->owner ?? $target->createdBy);
            if (! $owner && method_exists($target, 'owner')) {
                $owner = $target->owner()->first();
            }
            $displayName = $owner?->display_name ?: $owner?->name;
            $handle = $owner?->handle;
            $avatarUrl = $owner?->avatar_url;
            $city = $target->city ?? $owner?->city;
            $badge = $owner?->badge;
            $excerptText = trim($target->title.($target->description ? " — {$target->description}" : ''));
            $excerpt = mb_substr(strip_tags($excerptText), 0, 150);

            $photos = $target->relationLoaded('photos') ? $target->photos : ($target->relationLoaded('media') ? $target->media : (method_exists($target, 'photos') ? $target->photos()->get() : collect()));
            $firstPhoto = $photos->first();
            $thumbnailUrl = $firstPhoto?->thumbnail_url ?? $firstPhoto?->url;
        } elseif ($target instanceof PostComment || $target instanceof ReelComment) {
            $author = $target->relationLoaded('user') ? $target->user : $target->user()->first();
            $displayName = $author?->display_name ?: $author?->name;
            $handle = $author?->handle;
            $avatarUrl = $author?->avatar_url;
            $city = $author?->city;
            $badge = $author?->badge;
            $excerpt = mb_substr(strip_tags((string) ($target->body ?? '')), 0, 150);
            $thumbnailUrl = $author?->avatar_url;
        } elseif ($target instanceof Message) {
            $sender = $target->relationLoaded('sender') ? $target->sender : $target->sender()->first();
            $displayName = $sender?->display_name ?: $sender?->name;
            $handle = $sender?->handle;
            $avatarUrl = $sender?->avatar_url;
            $city = $sender?->city;
            $badge = $sender?->badge;
            $excerpt = mb_substr(strip_tags((string) ($target->body ?? '')), 0, 150);
            $thumbnailUrl = $sender?->avatar_url;
        } elseif ($target instanceof Page) {
            $displayName = $target->name;
            $handle = $target->handle;
            $avatarUrl = $target->logo_url;
            $city = $target->city;
            $badge = $target->certification_status?->value ?? null;
            $excerpt = mb_substr(strip_tags((string) ($target->description ?? '')), 0, 150);
            $thumbnailUrl = $target->cover_url ?? $target->logo_url;
        }

        // Fallbacks depuis raw metadata si target n'a pu être chargé
        if (! $displayName && isset($this->attributes['metadata'])) {
            $rawMeta = json_decode($this->attributes['metadata'] ?? '[]', true) ?: [];
            $displayName = $rawMeta['target_label'] ?? null;
            $thumbnailUrl = $thumbnailUrl ?? ($rawMeta['target_thumbnail_url'] ?? null);
            $excerpt = $excerpt ?? ($rawMeta['target_excerpt'] ?? $this->details);
        }

        $contentLabel = match ($type) {
            'account' => $displayName,
            'listing' => ($target instanceof Listing ? $target->title : null) ?? $excerpt ?? $displayName,
            'page' => ($target instanceof Page ? $target->name : null) ?? $displayName,
            default => $excerpt ?: $displayName,
        };

        return [
            'type' => $type,
            'id' => $targetId,
            'display_name' => $displayName,
            'handle' => $handle,
            'avatar_url' => $avatarUrl,
            'city' => $city,
            'badge' => $badge,
            'excerpt' => $excerpt,
            'thumbnail_url' => $thumbnailUrl,
            'label' => $contentLabel, // Compatibilité
            'target_type' => $shortType,
            'available_actions' => $this->available_actions,
        ];
    }

    /**
     * Type court et normalisé de la cible (ex: 'page', 'user', 'post', 'reel', 'listing', 'comment', 'message').
     */
    public function getTargetTypeAttribute(): string
    {
        return static::resolveShortType($this->reportable_type);
    }

    /**
     * Actions de modération proposées et admissibles pour cette cible.
     *
     * @return array<int, string>
     */
    public function getAvailableActionsAttribute(): array
    {
        return match ($this->target_type) {
            'page' => [
                'warn_page',
                'suspend_page',
                'unsuspend_page',
                'keep_online',
                'request_correction',
            ],
            'user' => [
                'warn_author',
                'suspend_author',
                'restrict_author',
                'keep_online',
                'request_correction',
            ],
            default => [
                'keep_online',
                'hide',
                'remove',
                'warn_author',
                'suspend_author',
                'restrict_author',
                'request_correction',
            ],
        };
    }
}
