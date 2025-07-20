<?php

namespace Mmedia\LeChat\Contracts;

use Illuminate\Support\Collection;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\Chatroom;

interface TargetedMessageSender
{
    /**
     * Sends a message to a chat or a participant
     */
    public function sendMessageTo(Chatroom|ChatParticipantInterface $recipient, string $message, bool $forceNewChannel = false, array $newChannelConfiguration = []): ChatMessage;

    /**
     * Gets all the messages sent to a chat or a specific participant
     */
    public function getMessagesSentTo(Chatroom|ChatParticipantInterface $target): Collection;
}
