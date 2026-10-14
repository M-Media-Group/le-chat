<?php

namespace Mmedia\LeChat\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Laravel\Ai\Contracts\ConversationStore;
use Mmedia\LeChat\Ai\ChatroomConversationStore;
use Mmedia\LeChat\Ai\RequesterEnvelope;
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

/**
 * Prompts the AI agents in a chatroom when a person posts a message.
 */
class PromptAgentsOnMessageCreated implements ShouldQueue
{
    /**
     * Keep messages in one chatroom from generating overlapping assistant turns.
     *
     * @return array<int, object>
     */
    public function middleware(MessageCreated $event): array
    {
        return [
            // (new WithoutOverlapping('le-chat-ai:'.$event->message->chatroom_id))
            //     ->releaseAfter(1)
            //     ->expireAfter(600),
        ];
    }

    public function handle(MessageCreated $event): void
    {
        $message = $event->message;
        $store = app(ConversationStore::class);

        if (! $store instanceof ChatroomConversationStore || ! $this->isFromPerson($message)) {
            return;
        }

        $room = $message->chatroom;
        $conversationId = $store->conversationIdOf($room);

        $agents = $room->participants()
            ->where('reference_id', 'like', ChatroomConversationStore::AGENT_REFERENCE_PREFIX.'%')
            ->get()
            ->filter(fn (ChatParticipant $agent) => $this->shouldRespond($agent, $message));

        foreach ($agents as $participant) {
            $agentClass = substr($participant->reference_id, strlen(ChatroomConversationStore::AGENT_REFERENCE_PREFIX));

            if (! class_exists($agentClass)) {
                continue;
            }

            $agent = is_subclass_of($agentClass, RespondsToChatMessages::class)
                ? $agentClass::forChatMessage($message)
                : app($agentClass);

            $store->answering($message, $agentClass, function () use ($agent, $conversationId, $message, $participant) {
                $showTyping = config('chat.ai.typing_indicators', true);

                $showTyping && $participant->startTyping();

                try {
                    $response = $agent->continue($conversationId, as: $message->sender?->participatingModel)->prompt(
                        RequesterEnvelope::current($message->sender->display_name ?? '', $message->message)
                    );
                } finally {
                    // Also runs when the model fails, so the indicator never sticks
                    $showTyping && $participant->stopTyping();
                }
            });
        }
    }

    /**
     * Whether the agent should answer this message. Every agent in the chatroom answers every message for now.
     */
    protected function shouldRespond(ChatParticipant $agent, ChatMessage $message): bool
    {
        return true;
    }

    /**
     * Messages the AI SDK wrote, system notices and the agents' own replies must not trigger an agent.
     */
    protected function isFromPerson(ChatMessage $message): bool
    {
        if ($message->sender_id === null || $message->deleted_at !== null || blank($message->message)) {
            return false;
        }

        if (isset($message->metadata['ai'])) {
            return false;
        }

        return ! str_starts_with((string) $message->sender?->reference_id, ChatroomConversationStore::AGENT_REFERENCE_PREFIX);
    }
}
