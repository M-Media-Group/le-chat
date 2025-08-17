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
     * @param  bool  $forceNewChannel  create a new channel even if one exists
     * @param  array  $newChannelConfiguration  configuration for the new channel if one is created
     */
    public function sendMessageTo(ChatMessage|Chatroom|ChatParticipantInterface|array $recipient, string $message, bool $forceNewChannel = false, array $newChannelConfiguration = []): ChatMessage;

    /**
     * Gets all the messages sent to a chat or a specific participant
     */
    public function getMessagesSentTo(Chatroom|ChatParticipantInterface $target): Collection;
}
