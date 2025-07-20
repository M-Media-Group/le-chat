<?php

namespace Mmedia\LaravelChat\Traits;

use Carbon\Carbon;
use DateTime;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Models\ChatMessage;
use Mmedia\LaravelChat\Models\ChatParticipant;
use Mmedia\LaravelChat\Models\Chatroom;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

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
    use HasRelationships;

    /**
     * The chat participants for this model (the inverse of the morphTo on ChatParticipant).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<ChatParticipant, M>
     */
    public function chatParticipants(): MorphMany
    {
        // 'participant' is the morph name defined in the ChatParticipant model's morphTo() method
        return $this->morphMany(ChatParticipant::class, 'participant')->chaperone();
    }

    /**
     * There is one chatParticipant per chat room for a given model using this trait.
     */
    public function asParticipantIn(Chatroom $chatRoom, bool $includeTrashed = false): ?ChatParticipant
    {
        // Get the chat participant for this model in the given chat room.
        // This will return a single ChatParticipant model instance.
        return $this->chatParticipants()
            ->where('chatroom_id', $chatRoom->getKey())
            ->when($includeTrashed, fn ($query) => $query->withTrashed())
            ->first();
    }

    /**
     * Get the chat rooms this model is a participant in.
     */
    public function chatRooms()
    {
        // We need to join ChatRooms through the ChatParticipants table.
        // Start from the Chatroom model query builder.
        return $this->hasManyDeepFromRelations(
            $this->chatParticipants(),
            (new ChatParticipant)->chatroom()
        );
    }

    public function messages()
    {
        // We need to get ChatMessages that are linked to a ChatParticipant
        // where that ChatParticipant is linked to this model.
        // Start from the ChatMessage model query builder.
        return $this->hasManyDeepFromRelations(
            $this->chatParticipants(),
            (new ChatParticipant)->messages()
        );
    }

    /**
     * Get the chat rooms this model is a participant in.
     *
     * Compared to above, this uses EXISTS (the above uses inner joins). It may be more efficient in some cases.
     *
     * @return \Illuminate\Database\Query\Builder<Chatroom>|\Illuminate\Database\Eloquent\Builder<Chatroom>
     */
    public function chatRoomsBuilder(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
    {
        // We need to join ChatRooms through the ChatParticipants table.
        // Start from the Chatroom model query builder.
        return Chatroom::havingParticipants([$this]);
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyDeep<ChatMessage, ChatParticipant>
     */
    public function sentMessages()
    {
        // We need to get ChatMessages that are linked to a ChatParticipant
        // where that ChatParticipant is linked to this model.
        // Start from the ChatMessage model query builder.
        return $this->hasManyDeepFromRelations(
            $this->chatParticipants(),
            (new ChatParticipant)->sentMessages()
        );
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     *
     * Compared to above, this uses EXISTS (the above uses inner joins). It may be more efficient in some cases.
     *
     * @return \Illuminate\Database\Query\Builder<ChatMessage>
     */
    public function sentMessagesBuilder(): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
    {
        // We need to get ChatMessages that are linked to a ChatParticipant
        // where that ChatParticipant is linked to this model.
        // Start from the ChatMessage model query builder.
        return ChatMessage::query()->sentBy($this);
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
    private function getMessagesQuery(bool $includeBeforeJoined = false): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
    {

        // Start building a query on the ChatMessage model
        return ChatMessage::query()
            ->visibleTo($this, true, $includeBeforeJoined)

            // Optional: Order the messages, typically by creation date
            ->orderBy('id', 'desc');
    }

    private function visibleMessages(?int $limit = null, ?int $offset = null, bool $includeBeforeJoined = false): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
    {
        // Start building a query on the ChatMessage model
        $query = $this->getMessagesQuery($includeBeforeJoined);

        // Apply pagination if limit is set
        if ($limit) {
            $query->limit($limit);
        }

        // Apply offset if set
        if ($offset) {
            $query->offset($offset);
        }

        // Execute the query and return the collection of ChatMessage models
        return $query;
    }

    /**
     * Loads all messages for the given model via the participant relationship. Filtered to only messages created after column created_at in the pivot table.
     *
     * @return \Illuminate\Support\Collection<ChatMessage, $this>
     */
    public function getMessages(?int $limit = null, ?int $offset = null, bool $includeBeforeJoined = false): Collection
    {
        // Get the messages using the visibleMessages method
        return $this->visibleMessages($limit, $offset, $includeBeforeJoined)->get();
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     */
    private function getMessagesSentToParticipant(ChatParticipantInterface|ChatParticipant $participant): Collection
    {
        return $this->sentMessages()
            ->visibleTo($participant)
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
     * Returns or creates a chatroom where only the current model is present, e.g. a private chatroom.
     *
     * This is useful for sending messages to the current model only, e.g. for notifications.
     *
     * @param  array<string, mixed>  $newChannelConfiguration
     */
    public function getOrCreatePersonalChatroom(array $newChannelConfiguration = []): Chatroom
    {
        // Get the chat room this model is a participant in.
        $chatRoom = $this->chatRooms()->havingExactlyParticipants([$this], true)->first();

        // If no chat room exists, create a new one with the given configuration
        if (! $chatRoom) {
            $chatRoom = Chatroom::create($newChannelConfiguration);
            // Add this model as a participant in the new chat room
            $this->chatParticipants()->create([
                'chatroom_id' => $chatRoom->getKey(),
                'participant_id' => $this->getKey(),
                'participant_type' => $this->getMorphClass(),
            ]);
        }

        return $chatRoom;
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     *
     * @return \Illuminate\Support\Collection<ChatMessage, $this>
     */
    private function getMessagesSentToChatRoom(Chatroom $chatRoom): Collection
    {
        return $this->sentMessages()
            // Filter the joined chat_participants records
            ->inRoom($chatRoom)
            ->get();
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     *
     * @return \Illuminate\Support\Collection<ChatMessage, $this>
     */
    public function getMessagesSentTo(Chatroom|ChatParticipantInterface|ChatParticipant $target): Collection
    {
        if ($target instanceof Chatroom) {
            return $this->getMessagesSentToChatRoom($target);
        }

        return $this->getMessagesSentToParticipant($target);
    }

    /**
     * Determines if the current model can send a message to the given chat room.
     */
    private function canSendMessageToChatRoom(Chatroom $chatRoom): bool
    {
        // Check if the current model is a participant in the chat room
        return $this->isParticipantIn($chatRoom);
    }

    /**
     * Sends a message to a chat room.
     *
     *
     * @throws \Exception if the current model is not an active participant in the chat room
     */
    private function sendMessageToChatRoom(Chatroom $chatRoom, string $message): ChatMessage
    {
        // Check if the current model can send a message to the chat room
        if (! $this->canSendMessageToChatRoom($chatRoom)) {
            throw new \Exception('Cannot send a message to the chat room because the current model is not an active participant in the chat room. If the model is deleted, you need to create a new chatroom.');
        }

        // Create a new message in the chat room
        return $chatRoom->messages()->create([
            'sender_id' => $this instanceof ChatParticipant
                ? $this->getKey()
                : $this->asParticipantIn($chatRoom, true)->getKey(),
            'message' => $message,
        ])->fresh();
    }

    /**
     * Sends a message to a participant, creating a new chat room if necessary.
     */
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
     * @param  Chatroom|ChatParticipant|ChatParticipantInterface|ChatParticipantInterface[]  $recipient
     */
    public function sendMessageTo(
        Chatroom|ChatParticipantInterface|array $recipient,
        string $message,
        bool $forceNewChannel = false,
        array $newChannelConfiguration = [],
        array $asParticipantConfiguration = []
    ): ChatMessage {
        if ($recipient instanceof Chatroom) {
            return $this->sendMessageToChatRoom($recipient, $message);
        }

        // Normalize the recipient to an array of ChatParticipantInterface
        if ($recipient instanceof ChatParticipantInterface || $recipient instanceof ChatParticipant) {
            $recipient = [$recipient];
        }

        if ($forceNewChannel || ! $bestChannel = $this->getBestChannelForParticipants($recipient)) {
            // If the current model is a ChatParticipant, it cannot create a new chat room because a participant belongs to one room
            if ($this instanceof ChatParticipant) {
                // While the code would create a new chatroom, it would also create a new ChatParticipant for this model from itself, which is confusing from a developers perspective. Here we throw an exception to indicate that the current model cannot create a new chatroom.
                throw new \Exception('Cannot send a message because no chatroom between the participants was found. The current model cannot create a new shared chatroom because channels cannot be created from or for a direct ChatParticipant. Use a higher-order model that implements the ChatParticipantInterface instead.');
            }
            // Create a new chat room with the given configuration
            $bestChannel = Chatroom::create($newChannelConfiguration);
            // Add this model and the recipient to the chat room
            $this->chatParticipants()->create([
                ...$asParticipantConfiguration,
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
     */
    public function isParticipantIn(Chatroom $chatRoom, bool $includeTrashed = false): bool
    {
        // Check if this model is a participant in the given chat room
        return $this->asParticipantIn($chatRoom, $includeTrashed) !== null;
    }

    public function isOrWasParticipantIn(Chatroom $chatRoom): bool
    {
        // Check if this model is a participant in the given chat room, including trashed participants
        return $this->asParticipantIn($chatRoom, true) !== null;
    }

    public function asChatParticipantFromMessageOrChatroom(ChatMessage|Chatroom $roomOrMessage): ?ChatParticipant
    {
        // Get the chat participant for this model in the given chat room or message
        if ($roomOrMessage instanceof ChatMessage) {
            return $this->asParticipantIn($roomOrMessage->chatroom);
        }

        return $this->asParticipantIn($roomOrMessage);
    }

    public function markRead(Chatroom|ChatMessage $roomOrMessage): bool
    {
        $chatParticipant = $this instanceof ChatParticipant
            ? $this
            :
            $this->asChatParticipantFromMessageOrChatroom($roomOrMessage);

        if (! $chatParticipant) {
            throw new \Exception('Cannot mark as read because the current model is not an active participant in the chat room. If the model is deleted, you need to create a new chatroom.');
        }

        $chatParticipant->read_at = $roomOrMessage instanceof ChatMessage
            ? $roomOrMessage->created_at
            : Carbon::now();

        return $chatParticipant->save();
    }

    /**
     * Mark the chat room or message as read at a specific time.
     */
    public function markReadUntil(Chatroom|ChatMessage $roomOrMessage, DateTime|Carbon $readAt): bool
    {
        $chatParticipant = $this->asChatParticipantFromMessageOrChatroom($roomOrMessage);

        if (! $chatParticipant) {
            throw new \Exception('Cannot mark as read because the current model is not an active participant in the chat room. If the model is deleted, you need to create a new chatroom.');
        }

        $chatParticipant->read_at = $readAt;

        return $chatParticipant->save();
    }

    /**
     * Determines if this model is current connected to the chatroom via sockets.
     *
     * This is useful to determine if the participant is online and can receive real-time notifications.
     */
    public function isConnectedToChatroomViaSockets(Chatroom $chatRoom): ?bool
    {
        $participant = $this->asParticipantIn($chatRoom);

        return $participant ? $participant->is_connected : null;
    }

    public function scopeWhereHasUnreadMessages($query, ?int $daysAgo = null, bool $includeSystemMessages = false)
    {
        return $query->whereHas('messages', function ($messagesQuery) use ($daysAgo, $includeSystemMessages) {
            $messagesTable = (new ChatMessage)->getTable();
            $participantsTable = (new ChatParticipant)->getTable();

            if ($daysAgo !== null) {
                $messagesQuery->whereDate("{$messagesTable}.created_at", '>=', now()->subDays($daysAgo));
            }

            $messagesQuery->when(! $includeSystemMessages, function ($q) use ($messagesTable) {
                // Exclude system messages
                $q->whereNotNull("{$messagesTable}.sender_id");
            });

            // 1. Participant must not be the sender of the message.
            // The sender_id on a chat_message links to a chat_participants.id.
            $messagesQuery->whereColumn(
                "{$messagesTable}.sender_id",
                '!=',
                "{$participantsTable}.id"
            );

            // 2. Message must have been created after the participant joined the room.
            // This ensures the participant could have seen the message.
            $messagesQuery->whereColumn(
                "{$messagesTable}.created_at",
                '>=',
                "{$participantsTable}.created_at"
            );

            // 3. Message is newer than the read timestamp OR the read timestamp is NULL.
            // This is the key fix to handle users who have never read the chat.
            $messagesQuery->where(function ($q) use ($messagesTable, $participantsTable) {
                $q->whereColumn("{$messagesTable}.created_at", '>', "{$participantsTable}.read_at")
                    ->orWhereNull("{$participantsTable}.read_at");
            });
        });
    }

    public function scopeWhereHasUnreadMessagesToday($query, bool $includeSystemMessages = false)
    {
        // Call the whereHasUnreadMessages method with 0 days ago to get today's unread messages
        return $query->whereHasUnreadMessages(0, $includeSystemMessages);
    }

    public function loadUnreadMessagesCount(bool $includeSystemMessages = false): self
    {
        $this->loadCount([
            'messages as unread_messages_count' => fn ($query) => $query->visibleTo($this)->unreadBy($this)
                ->when(! $includeSystemMessages, function ($q) {
                    // Exclude system messages
                    $q->whereNotNull('sender_id');
                }),
        ]);

        return $this;
    }
}
