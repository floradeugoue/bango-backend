<?php

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreVoiceMessageRequest;
use App\Http\Resources\Message\MessageResource;
use App\Models\Message\Conversation;
use App\Services\Message\VoiceMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class VoiceMessageController extends Controller
{
    public function __construct(
        protected VoiceMessageService $voiceMessageService
    ) {}

    /**
     * Envoi d'une note vocale dans une conversation.
     *
     * POST /api/messages/threads/{id}/voice
     */
    public function store(string $threadId, StoreVoiceMessageRequest $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($threadId);

        Gate::authorize('sendMessage', $thread);

        $message = $this->voiceMessageService->storeVoiceNote(
            conversation: $thread,
            sender: $request->user(),
            audioFile: $request->file('voice'),
            options: $request->validated()
        );

        return (new MessageResource($message))
            ->response()
            ->setStatusCode(201);
    }
}
