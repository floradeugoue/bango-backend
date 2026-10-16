<?php

namespace App\Models\Message;

use App\Enums\Message\MessageType;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $id
 * @property string $conversation_id
 * @property int|null $sender_id
 * @property MessageType $type
 * @property string|null $body
 * @property string|null $client_message_id
 * @property string|null $reply_to_message_id
 * @property string|null $attachment_path
 * @property string|null $attachment_mime
 * @property int|null $attachment_size
 * @property int|null $attachment_duration_ms
 * @property array|null $metadata
 * @property bool $is_retracted
 * @property Carbon|null $retracted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Conversation $conversation
 * @property-read User|null $sender
 * @property-read Message|null $replyTo
 * @property-read string|null $attachment_url
 */
class Message extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'messages';

    protected $fillable = [
        'id',
        'conversation_id',
        'sender_id',
        'type',
        'body',
        'client_message_id',
        'reply_to_message_id',
        'attachment_path',
        'attachment_mime',
        'attachment_size',
        'attachment_duration_ms',
        'metadata',
        'is_retracted',
        'retracted_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'metadata' => 'array',
            'is_retracted' => 'boolean',
            'retracted_at' => 'datetime',
            'attachment_size' => 'integer',
            'attachment_duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    /**
     * Get computed URL for attachment.
     */
    public function getAttachmentUrlAttribute(): ?string
    {
        if (! $this->attachment_path) {
            return null;
        }

        if (filter_var($this->attachment_path, FILTER_VALIDATE_URL)) {
            return $this->attachment_path;
        }

        return Storage::disk(config('media.disk', 'public'))->url($this->attachment_path);
    }

    /**
     * Check if message is a voice note.
     */
    public function isVoice(): bool
    {
        return $this->type === MessageType::Voice;
    }

    /**
     * Check if message is a story reply.
     */
    public function isStoryReply(): bool
    {
        return $this->type === MessageType::StoryReply;
    }

    /**
     * Retract this message.
     */
    public function retract(): void
    {
        $this->update([
            'is_retracted' => true,
            'retracted_at' => Carbon::now(),
            'body' => null,
            'attachment_path' => null,
        ]);
    }
}
