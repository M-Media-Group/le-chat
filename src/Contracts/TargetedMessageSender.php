<?php

namespace Mmedia\LeChat\Contracts;

use Illuminate\Support\Collection;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\Chatroom;

interface TargetedMessageSender
{
    /**
     * Sends a message to a chat or a participant
     *
     * @param  ChatMessage|Chatroom|ChatParticipantInterface|array<ChatParticipantInterface>  $recipient
     * @param  bool  $forceNewChannel  create a new channel even if one exists
     * @param  array  $newChannelConfiguration  configuration for the new channel if one is created
     * @param  array  $asParticipantConfiguration  configuration for this participant if a new channel is created
     * @param  array  $messageConfiguration  configuration for the message
     */
    public function sendMessageTo(ChatMessage|Chatroom|ChatParticipantInterface|array $recipient, string $message, bool $forceNewChannel = false, array $newChannelConfiguration = [], array $asParticipantConfiguration = [], array $messageConfiguration = []): ChatMessage;

    /**
     * Gets all the messages sent to a chat or a specific participant
     */
    public function getMessagesSentTo(Chatroom|ChatParticipantInterface $target): Collection;

    /** Sends a typing status to a chat or a specific participant */
    public function sendTypingStatusTo(Chatroom|ChatParticipantInterface $target, bool $isTyping): void;
}
