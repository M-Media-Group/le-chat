<?php

namespace Mmedia\LeChat\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Mmedia\LeChat\Ai\ChatroomConversationStore;
use Mmedia\LeChat\Ai\RequesterEnvelope;
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Throwable;

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
            (new WithoutOverlapping('le-chat-ai:' . $event->message->chatroom_id))
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    /**
     * Typed as the SDK contract so static analysis doesn't narrow the container binding to our store.
     */
    private function conversationStore(): ConversationStore
    {
        return app(ConversationStore::class);
    }

    public function handle(MessageCreated $event): void
    {
        $message = $event->message;

        $store = $this->conversationStore();

        if (! $store instanceof ChatroomConversationStore || ! $this->isFromPerson($message)) {
            return;
        }

        $room = $message->chatroom;
        $conversationId = $store->conversationIdOf($room);

        $pendingApprovals = $store->pendingApprovalsFor($conversationId);

        if ($pendingApprovals !== []) {
            $pausedMessage = $room->messages()
                ->where('metadata->ai->status', 'paused')
                ->latest('id')
                ->first();

            $room->sendMessage('The assistant needs your approval before it can continue.', [
                'metadata' => ['approval_required' => [
                    'type' => 'approval_required',
                    'ai' => $pausedMessage?->metadata['ai'] ?? [],
                    'approvals' => array_map(
                        fn(PendingApproval $approval): array => $approval->toArray(),
                        $pendingApprovals,
                    ),
                ]],
            ]);

            return;
        }

        $agents = $room->participants()
            ->where('reference_id', 'like', ChatroomConversationStore::AGENT_REFERENCE_PREFIX . '%')
            ->get()
            ->filter(fn(ChatParticipant $agent) => $this->shouldRespond($agent, $message));

        foreach ($agents as $participant) {
            $agentClass = substr($participant->reference_id, strlen(ChatroomConversationStore::AGENT_REFERENCE_PREFIX));

            if (! class_exists($agentClass)) {
                continue;
            }

            $agent = is_subclass_of($agentClass, RespondsToChatMessages::class)
                ? $agentClass::forChatMessage($message)
                : app($agentClass);

            $store->answering($message, $agentClass, function () use ($agent, $conversationId, $message, $participant) {
                $showTyping = Features::optionEnabled(Features::aiSdkIntegration(), 'typing_indicators');

                $showTyping && $participant->startTyping();

                $agentIsConversational = method_exists($agent, 'continue');

                try {
                    $prompt = [
                        RequesterEnvelope::current($message->sender->display_name ?? '', $message->message),
                        ...config('chat-options.' . Features::aiSdkIntegration() . '.prompt_config', []),
                    ];

                    if ($agentIsConversational) {
                        $agent->continue($conversationId, as: $message->sender?->participatingModel);
                    }

                    $response = $agent->prompt(...$prompt);

                    if (! $agentIsConversational) {
                        $participant->sendMessage($response, ['metadata' => ['ai' => [
                            'conversation_id' => $response->conversationId,
                            'conversation_user' => $response->conversationUser,
                            'user_message_id' => $response->userMessageId,
                            'assistant_message_id' => $response->assistantMessageId,
                            'messages' => $response->messages,
                            'tool_calls' => $response->toolCalls ?? null,
                            'tool_results' => $response->toolResults ?? null,
                            'steps' => $response->steps ?? null,
                            'pending_approvals' => $response->pendingApprovals ?? null,
                            'reasoning' => $response->reasoning ?? null,
                        ]]]);
                    }
                } finally {
                    // Also runs when the model fails, so the indicator never sticks
                    $showTyping && $participant->stopTyping();
                }
            });
        }
    }

    public function failed(MessageCreated $event, Throwable $exception): void
    {
        $event->message->chatroom->sendMessage("Sorry, I couldn't respond just now. Please try again shortly.");
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

    /**
     * Calculate the number of seconds to wait before retrying the queued listener.
     *
     * @return list<int>
     */
    public function backoff(MessageCreated $event): array
    {
        return [1, 5, 10];
    }
}
