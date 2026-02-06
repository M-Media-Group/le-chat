<?php

namespace Mmedia\LeChat\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

/**
 * @template T of \Illuminate\Database\Eloquent\Model
 */
interface ChatParticipantInterface extends TargetedMessageSender
{
    /**
     * Get the chat participants for this model (the inverse of the morphTo on ChatParticipant).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<ChatParticipant, T>
     */
    public function chatParticipants(): MorphMany;

    /**
     * Get the chat rooms this model is a participant in.
     */
    public function chatRooms();

    /**
     * Get all messages sent by this model across all their chat participants.
     */
    public function sentMessages();

    /**
     * Get the class name for polymorphic relations.
     *
     * @see \Illuminate\Database\Eloquent\Concerns\HasRelationships::getMorphClass()
     *
     * @return class-string<T>
     */
    public function getMorphClass();

    /**
     * Get the value of the model's primary key.
     *
     * @see \Illuminate\Database\Eloquent\Model::getKey()
     *
     * @return mixed
     */
    public function getKey();

    /**
     * Replies to a message in the chat.
     */
    public function replyTo(ChatMessage $originalMessage, string $message): ChatMessage;

    /**
     * Determines if this model is a participant in the given chat room.
     */
    public function isParticipantIn(Chatroom $chatRoom, bool $includeTrashed = false): bool;

    /**
     * There is one chatParticipant per chat room for a given model using this trait.
     */
    public function asParticipantIn(Chatroom $chatRoom, bool $includeTrashed = false): ?ChatParticipant;

    /**
     * Get the name of the "created at" column.
     *
     * @return string|null
     */
    public function getCreatedAtColumn();

    /**
     * Qualify the given column name with the model's table.
     *
     * @param  string  $column
     * @return string
     */
    public function qualifyColumn($column);

    /**
     * Get the messages that are visible to this participant.
     *
     * @return \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<ChatMessage>
     */
    public function visibleMessages(?int $limit = null, ?int $offset = null, bool $includeBeforeJoined = false): \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder;

    /**
     * Loads all messages for the given model via the participant relationship. Filtered to only messages created after column created_at in the pivot table.
     *
     * @return \Illuminate\Support\Collection<int, ChatMessage>
     */
    public function getMessages(?int $limit = null, ?int $offset = null, bool $includeBeforeJoined = false): \Illuminate\Support\Collection;

    /**
     * Returns or creates a chatroom where only the current model is present, e.g. a private chatroom.
     *
     * This is useful for sending messages to the current model only, e.g. for notifications.
     *
     * @param  array<string, mixed>  $newChannelConfiguration
     */
    public function getOrCreatePersonalChatroom(array $newChannelConfiguration = []): Chatroom;

    /**
     * Mark a given message or chatroom as read for this participant.
     */
    public function markRead(Chatroom|ChatMessage $roomOrMessage): bool;

    /**
     * Get the display name for this participant.
     *
     * This is used to display the participant in the chat UI.
     */
    public function getDisplayName(): ?string;

    /**
     * Get the avatar URL for this participant.
     *
     * This is used to display the participant's avatar in the chat UI.
     */
    public function getAvatarUrl(): ?string;
}
