<?php

namespace Mmedia\LaravelChat\Traits;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Models\ChatMessage;
use Mmedia\LaravelChat\Models\ChatParticipant;
use Mmedia\LaravelChat\Models\Chatroom;

/**
 * @template T of \Mmedia\LaravelChat\Contracts\ChatParticipantInterface
 *
 * @mixin T
 *
 * @template M of \Illuminate\Database\Eloquent\Model
 *
 * @mixin M
 *
 * @phpstan-require-implements \Mmedia\LaravelChat\Contracts\ChatParticipantInterface
 *
 * @see \Mmedia\LaravelChat\Contracts\ChatParticipantInterface
 */
trait IsChatParticipant
{
    /**
     * The chat participants for this model (the inverse of the morphTo on ChatParticipant).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<ChatParticipant, M>
     */
    public function chatParticipants(): MorphMany
    {
        // 'participant' is the morph name defined in the ChatParticipant model's morphTo() method
        return $this->morphMany(ChatParticipant::class, 'participant');
    }

    /**
     * There is one chatParticipant per chat room for a given model using this trait.
     */
    private function asChatParticipantIn(Chatroom $chatRoom): ?ChatParticipant
    {
        // Get the chat participant for this model in the given chat room.
        // This will return a single ChatParticipant model instance.
        return $this->chatParticipants()
            ->where('chatroom_id', $chatRoom->getKey())
            ->first();
    }

    /**
     * Get the chat rooms this model is a participant in.
     *
     * @return \Illuminate\Database\Query\Builder<Chatroom>|\Illuminate\Database\Eloquent\Builder<Chatroom>
     */
    public function chatRooms(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
    {
        // We need to join ChatRooms through the ChatParticipants table.
        // Start from the Chatroom model query builder.
        return Chatroom::havingParticipants([$this]);
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     *
     * @return \Illuminate\Database\Query\Builder<ChatMessage>
     */
    public function sentMessages(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
    {
        // We need to get ChatMessages that are linked to a ChatParticipant
        // where that ChatParticipant is linked to this model.
        // Start from the ChatMessage model query builder.
        return ChatMessage::query()
            ->sentByParticipant($this);
        // Distinct is likely not needed here.
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     */
    public function getSentMessages(): \Illuminate\Database\Eloquent\Collection
    {

        return $this->sentMessages()->get();
    }

    /**
     * Loads all messages for the given model via the participant relationship. Filtered to only messages created after column created_at in the pivot table.
     *
     * @return \Illuminate\Database\Query\Builder<ChatMessage>
     */
    private function getMessagesQuery(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
    {

        // Start building a query on the ChatMessage model
        return ChatMessage::query()
            ->canBeReadByParticipant($this)

            // Optional: Order the messages, typically by creation date
            ->orderBy('id', 'desc');
    }

    /**
     * Loads all messages for the given model via the participant relationship. Filtered to only messages created after column created_at in the pivot table.
     *
     * @return \Illuminate\Support\Collection<ChatMessage, $this>
     */
    public function getMessages(): Collection
    {

        // Start building a query on the ChatMessage model
        return $this->getMessagesQuery()
            // Add the core condition: only include messages created *after* the participant joined.
            // We compare the 'created_at' column on the messages table with the 'created_at'
            // column on the joined participants table.
            ->where('chat_messages.created_at', '>=', $this->created_at)
            // Execute the query and return the collection of ChatMessage models
            ->get();
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     */
    private function getMessagesSentToParticipant(ChatParticipantInterface|ChatParticipant $participant): Collection
    {
        return $this->sentMessages()
            // Filter the joined chat_participants records
            ->canBeReadByParticipant($participant)
            ->distinct()
            ->get();
    }

    /**
     * Get the best channel for the given participants. Returns the latest used chatroom that contains all and only the given participants (plus this model).
     *
     * @param  (ChatParticipantInterface|ChatParticipant)[]  $participants
     */
    public function getBestChannelForParticipants(array $participants): ?Chatroom
    {
        // Add the current model to the list of participants to count
        $allParticipants = $participants;
        $allParticipants[] = $this; // Add this model (which implements ChatParticipantInterface)

        // Get the chat rooms this model is a participant in.
        return $this->chatRooms()->havingExactlyParticipants($allParticipants)
            // Limit to the latest one
            ->first();
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     */
    private function getMessagesSentToChatRoom(Chatroom $chatRoom): Collection
    {
        return $this->sentMessages()
            // Filter the joined chat_participants records
            ->where('chatroom_id', $chatRoom->getKey())
            ->get();
    }

    public function getMessagesSentTo(Chatroom|ChatParticipantInterface|ChatParticipant $target): Collection
    {
        if ($target instanceof Chatroom) {
            return $this->getMessagesSentToChatRoom($target);
        }

        return $this->getMessagesSentToParticipant($target);
    }

    private function sendMessageToChatRoom(Chatroom $chatRoom, string $message): ChatMessage
    {
        // Create a new message in the chat room
        return $chatRoom->messages()->create([
            'sender_id' => $this->asChatParticipantIn($chatRoom)->getKey(),
            'message' => $message,
        ])->fresh();
    }

    private function sendMessageToParticipant(ChatParticipantInterface $participant, string $message): ChatMessage
    {
        $bestChannel = $this->getBestChannelForParticipants([$participant]);
        if (! $bestChannel) {
            throw new \Exception('No chat room found for the given participants');
        }

        return $this->sendMessageToChatRoom($bestChannel, $message);
    }

    /**
     * Sends a message to a chat or a participant
     *
     * @param  Chatroom|ChatParticipantInterface|ChatParticipantInterface[]  $recipient
     */
    public function sendMessageTo(Chatroom|ChatParticipantInterface|array $recipient, string $message, bool $forceNewChannel = false, array $newChannelConfiguration = []): ChatMessage
    {
        if ($recipient instanceof Chatroom) {
            return $this->sendMessageToChatRoom($recipient, $message);
        }

        // Normalize the recipient to an array of ChatParticipantInterface
        if ($recipient instanceof ChatParticipantInterface) {
            $recipient = [$recipient];
        }

        if ($forceNewChannel || ! $bestChannel = $this->getBestChannelForParticipants($recipient)) {
            // Create a new chat room with the given configuration
            $bestChannel = Chatroom::create($newChannelConfiguration);
            // Add this model and the recipient to the chat room
            $this->chatParticipants()->create([
                'chatroom_id' => $bestChannel->getKey(),
                'participant_id' => $this->getKey(),
                'participant_type' => $this->getMorphClass(),
                'role' => 'admin',
            ]);

            foreach ($recipient as $participant) {
                $bestChannel->participants()->create([
                    'chatroom_id' => $bestChannel->getKey(),
                    'participant_id' => $participant->getKey(),
                    'participant_type' => $participant->getMorphClass(),
                ]);
            }
        }

        // Send the message to the best channel
        return $this->sendMessageToChatRoom($bestChannel, $message);
    }

    /**
     * Determines if this model is a participant in the given chat room.
     *
     * @param \Mmedia\LaravelChat\Models\Chatroom $chatRoom
     * @return bool
     */
    public function isParticipantIn(Chatroom $chatRoom): bool
    {
        // Check if this model is a participant in the given chat room
        return $this->asChatParticipantIn($chatRoom) !== null;
    }
}
