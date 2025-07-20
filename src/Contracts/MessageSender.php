<?php

namespace Mmedia\LeChat\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Mmedia\LeChat\Models\Chat;

interface MessageSender
{
    /**
     * Gets all the messages sent, either by the chat (system messages) or by the participant
     */
    public function getSentMessages(): Collection;
}
