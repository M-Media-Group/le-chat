<?php

namespace Mmedia\LeChat\Contracts;

use Mmedia\LeChat\Models\ChatMessage;

interface ApprovalPolicy
{
    public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool;

    /** Return true to resume, false to deny, or a string to record a denial reason. */
    public function mayResume(ChatMessage $requester, array $approvals): bool|string;
}
