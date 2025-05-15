<?php

namespace Mmedia\LaravelChat\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Mmedia\LaravelChat\Models\Chatroom;

interface SeesMessages
{
    public function setMessageAsSeen(string $messageId, bool $seen): void;

    public function setAllMessagesAsSeen(bool $seen): void;

    public function setAllMessagesInChatAsSeen(Chatroom $chat, bool $seen): void;

    public function getMessages(): Collection;
}
