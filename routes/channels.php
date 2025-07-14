<?php

namespace Mmedia\LaravelChat;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Http\Resources\ChatParticipantResource;
use Mmedia\LaravelChat\Models\ChatParticipant;
use Mmedia\LaravelChat\Models\Chatroom;

Broadcast::channel((new Chatroom)->broadcastChannelRoute(), function (ChatParticipantInterface|ChatParticipant $user, Chatroom $chatroom) {
    Log::info('Broadcasting channel check', [
        'user_id' => $user->getKey(),
        'chatroom_id' => $chatroom->getKey(),
        'has_participant' => $chatroom->hasParticipant($user),
        'participant' => $chatroom->participant($user),
    ]);
    return $chatroom->hasParticipant($user) ?
        ChatParticipantResource::make($chatroom->participant($user))->resolve() :
        false;
});
