<?php

namespace App\Http\Resources\Story;

use App\Enums\Story\StoryStickerType;
use App\Models\Story\StoryPollVote;
use App\Models\Story\StorySticker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StorySticker
 */
class StoryStickerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $userVote = null;
        $pollResults = null;

        if ($this->type === StoryStickerType::Poll) {
            $options = $this->data['options'] ?? ['Oui', 'Non'];

            if ($user) {
                $vote = StoryPollVote::where('sticker_id', $this->id)
                    ->where('user_id', $user->id)
                    ->first();
                $userVote = $vote ? (int) $vote->option_index : null;
            }

            // Calcul des pourcentages et totaux
            $allVotes = StoryPollVote::where('sticker_id', $this->id)->get();
            $totalVotes = $allVotes->count();

            $results = [];
            foreach ($options as $index => $text) {
                $count = $allVotes->where('option_index', $index)->count();
                $percentage = $totalVotes > 0 ? round(($count / $totalVotes) * 100) : 0;
                $results[] = [
                    'index' => $index,
                    'text' => $text,
                    'count' => $count,
                    'percentage' => $percentage,
                ];
            }

            $pollResults = [
                'total_votes' => $totalVotes,
                'options' => $results,
                'user_voted' => $userVote !== null,
                'user_voted_index' => $userVote,
            ];
        }

        return [
            'id' => (string) $this->id,
            'type' => $this->type->value,
            'x' => (float) $this->x,
            'y' => (float) $this->y,
            'scale' => (float) $this->scale,
            'rotation' => (float) $this->rotation,
            'data' => $this->data,
            'poll_results' => $pollResults,
        ];
    }
}
