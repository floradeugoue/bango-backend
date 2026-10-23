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
            'user', 'users', 'profile' => User::class,
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
            Post::class => 'post',
            PostComment::class => 'post_comment',
            Reel::class => 'reel',
            ReelComment::class => 'reel_comment',
            Listing::class => 'listing',
            Message::class => 'message',
            User::class => 'user',
            Page::class => 'page',
            default => class_basename($className),
        };
    }
}
