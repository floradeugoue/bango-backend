<?php

namespace App\Events\Story;

use App\Models\Story\StoryReply;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Événement métier signalant la création d'une réponse ou réaction privée sur une Story.
 *
 * Cet événement expose un contrat d'intégration stable et documenté pour le futur module Messagerie :
 * il met à disposition les identifiants, les intervenants, le contenu de l'interaction
 * ainsi que le snapshot nécessaire à l'affichage d'une carte d'aperçu Story dans la future conversation.
 *
 * NOTE IMPORTANTE : Cet événement ne prétend PAS qu'un message de messagerie a déjà été envoyé
 * ni qu'une conversation a été créée. Il notifie la disponibilité d'une interaction Story prête
 * pour intégration ultérieure par le consommateur dédié.
 */
class StoryReplyCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $integrationPayload
     */
    public array $integrationPayload;

    public function __construct(public StoryReply $reply)
    {
        $this->integrationPayload = [
            'event' => 'story.reply.created',
            'version' => '1.0',
            'interaction_id' => (string) $reply->id,
            'story_id' => (string) $reply->story_id,
            'story_slide_id' => $reply->story_slide_id ? (string) $reply->story_slide_id : null,
            'author_id' => (int) $reply->story_author_id,
            'respondent_id' => (int) $reply->user_id,
            'type' => $reply->type->value,
            'text' => $reply->text,
            'reaction' => $reply->reaction,
            'status' => $reply->status,
            'idempotency_key' => $reply->idempotency_key,
            'story_preview_card' => $reply->metadata ?? [],
            'created_at' => $reply->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
