<?php

namespace Mmedia\LeChat\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Models\ChatMessage;

class BlockInteracted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    private string $encryptedValue;

    public function __construct(
        public ChatMessage $message,
        public ChatParticipantInterface $actor,
        public string $blockId,
        public string $actionId,
        mixed $value,
    ) {
        $this->encryptedValue = encrypt(json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function value(): mixed
    {
        return json_decode(decrypt($this->encryptedValue), true, flags: JSON_THROW_ON_ERROR);
    }
}
