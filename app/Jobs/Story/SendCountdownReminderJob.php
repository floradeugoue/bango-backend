<?php

namespace App\Jobs\Story;

use App\Models\Story\StorySticker;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendCountdownReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $stickerId,
        public int $userId
    ) {}

    public function handle(): void
    {
        // 1. Vérifier si le rappel est toujours actif (non annulé par DELETE stories/stickers/{id}/remind)
        $reminderExists = DB::table('story_sticker_answers')
            ->where('sticker_id', $this->stickerId)
            ->where('user_id', $this->userId)
            ->where('type', 'countdown_reminder')
            ->exists();

        if (! $reminderExists) {
            return;
        }

        /** @var StorySticker|null $sticker */
        $sticker = StorySticker::with(['slide.story'])->find($this->stickerId);
        /** @var User|null $user */
        $user = User::find($this->userId);

        if (! $sticker || ! $user) {
            return;
        }

        $story = $sticker->slide?->story;
        if (! $story) {
            return;
        }

        try {
            $user->notify(new SocialNotification('countdown_remind', $user, [
                'story_id' => (string) $story->id,
                'sticker_id' => (string) $sticker->id,
            ]));
        } catch (Throwable $e) {
            Log::warning("Notification countdown reminder échouée : {$e->getMessage()}");
        }
    }
}
