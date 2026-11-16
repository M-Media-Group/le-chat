<?php

namespace Mmedia\LeChat\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\AgentResponse;
use Mmedia\LeChat\Ai\ApprovalBatches;
use Mmedia\LeChat\Ai\ChatAttachments;
use Mmedia\LeChat\Ai\ChatroomConversationStore;
use Mmedia\LeChat\Ai\DelegationTools;
use Mmedia\LeChat\Ai\RequesterEnvelope;
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Support\ChatMentions;
use Throwable;

/**
 * Prompts the AI agents in a chatroom when a person posts a message.
 */
class PromptAgentsOnMessageCreated implements ShouldQueue
{
    /** @var array{targeted: bool, participant_ids: list<int>}|null */
    protected ?array $resolvedMentions = null;

    /**
     * Keep messages in one chatroom from generating overlapping assistant turns.
     *
     * @return array<int, object>
     */
    public function middleware(MessageCreated $event): array
    {
        return [
            (new WithoutOverlapping('le-chat-ai:'.$event->message->chatroom_id))
                ->shared()
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
        $this->resolvedMentions = null;
        $message = $event->message->fresh();

        $store = $this->conversationStore();

        if ($message === null || ! $store instanceof ChatroomConversationStore || ! $this->isFromPerson($message)
            || ! Features::optionEnabled(Features::aiSdkIntegration(), 'auto_respond')) {
            return;
        }

        $room = $message->chatroom;
        $room->load('participants');
        $agents = $room->participants
            ->filter(fn (ChatParticipant $agent): bool => str_starts_with((string) $agent->reference_id, ChatroomConversationStore::AGENT_REFERENCE_PREFIX))
            ->filter(fn (ChatParticipant $agent) => $this->shouldRespond($agent, $message));
        if ($agents->isEmpty()) {
            return;
        }
        $conversationId = $store->conversationIdOf($room);

        $pendingApprovals = $store->pendingApprovalsFor($conversationId);

        if ($pendingApprovals !== [] && ! app(ApprovalBatches::class)->supersedeWith($message)) {
            $pausedMessage = $room->messages()
                ->where('metadata->ai->status', 'paused')
                ->latest('id')
                ->first();

            $room->sendMessage('The assistant needs your approval before it can continue.', [
                'metadata' => ['approval_required' => [
                    'type' => 'approval_required',
                    'ai' => $pausedMessage?->metadata['ai'] ?? [],
                    'approvals' => array_map(
                        fn (PendingApproval $approval): array => $approval->toArray(),
                        $pendingApprovals,
                    ),
                ]],
            ]);

            return;
        }

        foreach ($agents as $participant) {
            if (! ChatMessage::query()->visibleTo($participant)->withoutTrashed()->whereKey($message->getKey())->exists()) {
                continue;
            }

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
                        RequesterEnvelope::current($message->sender->display_name ?? '', $message->message ?? ''),
                        ...config('chat-options.'.Features::aiSdkIntegration().'.prompt_config', []),
                    ];

                    if (Features::enabled(Features::files())) {
                        $attachments = ChatAttachments::fromFiles($message->files()->get());
                        if ($attachments !== []) {
                            if (array_key_exists(1, $prompt)) {
                                $prompt[1] = [...$prompt[1], ...$attachments];
                            } else {
                                $prompt['attachments'] = [...($prompt['attachments'] ?? []), ...$attachments];
                            }
                        }
                    }

                    if ($agentIsConversational) {
                        $agent->continue($conversationId, as: $message->sender?->participatingModel);
                    }

                    $response = DelegationTools::within($agent, fn () => $agent->prompt(...$prompt));

                    if ($response instanceof AgentResponse && $response->hasPendingApprovals()) {
                        if (! $agentIsConversational || $response->assistantMessageId === null) {
                            throw new \LogicException('Tool approvals require a persisted conversational agent.');
                        }
                        $paused = $message->chatroom->messages()->findOrFail($response->assistantMessageId);
                        app(ApprovalBatches::class)->create($message, $paused);
                    }

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
        $message = $event->message;
        $hasFiles = Features::enabled(Features::files()) && (
            $message->files()->exists()
            || ($message->sender !== null && $message->chatroom->messages()
                ->visibleTo($message->sender)->withoutTrashed()->whereHas('files')->exists())
        );
        $message->chatroom->sendMessage($hasFiles
            ? "Sorry, I couldn't process that message or its attachments. Please try again."
            : "Sorry, I couldn't respond just now. Please try again shortly.");
    }

    /** Whether the agent should answer before constructing or prompting a model. */
    protected function shouldRespond(ChatParticipant $agent, ChatMessage $message): bool
    {
        if (! Features::optionEnabled(Features::aiSdkIntegration(), 'auto_respond')) {
            return false;
        }
        $mode = $agent->aiResponseMode();
        if ($mode === 'off') {
            return false;
        }
        $mentions = $this->messageMentions($message);

        return $mentions['targeted']
            ? in_array($agent->getKey(), $mentions['participant_ids'], true)
            : $mode === 'all';
    }

    /** @return array{targeted: bool, participant_ids: list<int>} */
    protected function messageMentions(ChatMessage $message): array
    {
        return $this->resolvedMentions ??= $this->resolveMentions($message);
    }

    /** @return array{targeted: bool, participant_ids: list<int>} */
    protected function resolveMentions(ChatMessage $message): array
    {
        return ChatMentions::resolve($message->chatroom, ChatMentions::text($message));
    }

    /**
     * Messages the AI SDK wrote, system notices and the agents' own replies must not trigger an agent.
     */
    protected function isFromPerson(ChatMessage $message): bool
    {
        if ($message->sender_id === null || $message->deleted_at !== null) {
            return false;
        }

        if (blank($message->message) && (! Features::enabled(Features::files()) || ! $message->files()->exists())) {
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
