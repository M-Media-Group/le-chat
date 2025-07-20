<?php

namespace Mmedia\LeChat;

use Illuminate\Support\Facades\Broadcast;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Http\Resources\ChatParticipantResource;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

Broadcast::channel((new Chatroom)->broadcastChannelRoute(), function (ChatParticipantInterface|ChatParticipant $user, Chatroom $chatroom) {
    return $chatroom->hasParticipant($user) ?
        ChatParticipantResource::make($chatroom->participant($user))->resolve() :
        false;
});
