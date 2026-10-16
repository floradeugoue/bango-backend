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
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'reporter_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'details',
        'status',
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

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
