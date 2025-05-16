<?php

namespace Mmedia\LaravelChat\Contracts;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Mmedia\LaravelChat\Models\ChatMessage;
use Mmedia\LaravelChat\Models\ChatParticipant;
use Mmedia\LaravelChat\Models\Chatroom;

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
     *
     * @return \Illuminate\Database\Query\Builder<Chatroom>
     */
    public function chatRooms(): Builder;

    /**
     * Get all messages sent by this model across all their chat participants.
     *
     * @return \Illuminate\Database\Query\Builder<ChatMessage>
     */
    public function sentMessages(): Builder;

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
     *
     * @param \Mmedia\LaravelChat\Models\Chatroom $chatRoom
     * @return bool
     */
    public function isParticipantIn(Chatroom $chatRoom): bool;
}
