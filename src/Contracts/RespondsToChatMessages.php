<?php

namespace Mmedia\LeChat\Contracts;

use Mmedia\LeChat\Models\ChatMessage;

/**
 * Implemented by AI SDK agents that answer messages posted in a chatroom.
 *
 * Agents that need constructor arguments the container cannot resolve (e.g. the signed-in user) must
 * implement this to be built for a message. Agents without it are created through the container.
 */
interface RespondsToChatMessages
{
    /**
     * Build the agent that will answer the given message.
     */
    public static function forChatMessage(ChatMessage $message): static;
}
