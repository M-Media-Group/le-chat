<?php

namespace Mmedia\LeChat\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

/**
 * @template T of \Illuminate\Database\Eloquent\Model
 */
interface ChatParticipantInterface extends MessageSender, TargetedMessageSender
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
}
