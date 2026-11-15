<?php

namespace Mmedia\LeChat\Ai;

use Mmedia\LeChat\Contracts\ApprovalPolicy;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;

/** @internal Default approval policy; applications may bind their own ApprovalPolicy. */
class RequesterApprovalPolicy implements ApprovalPolicy
{
    public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
    {
        return $requester->sender_id !== null && $actor->getKey() !== null
            && ChatParticipant::query()
                ->where('chatroom_id', $requester->chatroom_id)
                ->whereKey($requester->sender_id)
                ->ofParticipant($actor)
                ->exists();
    }

    public function mayResume(ChatMessage $requester, array $approvals): bool|string
    {
        if ($requester->deleted_at !== null || $requester->sender_id === null) {
            return 'The requester message is no longer available.';
        }

        $sender = ChatParticipant::query()
            ->where('chatroom_id', $requester->chatroom_id)
            ->find($requester->sender_id);

        if ($sender === null) {
            return 'The requester is no longer a chatroom participant.';
        }

        return $sender->participatingModel !== null ? true : 'The requester is no longer available.';
    }
}
