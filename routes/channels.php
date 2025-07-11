<?php

namespace Mmedia\LaravelChat;

use Illuminate\Support\Facades\Broadcast;
use Mmedia\LaravelChat\Models\Chatroom;

Broadcast::channel('chatroom.{chatroom}', function ($user, Chatroom $chatroom) {
    return $chatroom->hasParticipant($user) ? $chatroom->participant($user) : false;
});
