<?php

use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Models\ChatMessage;

class AiStoreOwner extends Model
{
    protected $table = 'ai_store_owners';

    protected $guarded = [];

    public function getDisplayName(): string
    {
        return 'Owner';
    }

    public function getAvatarUrl(): ?string
    {
        return null;
    }
}

class AiStoreAgent implements Agent
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'Be helpful.';
    }
}

class AiStoreEchoAgent implements RespondsToChatMessages
{
    /** @var list<array{text: string, history: list<string>}> */
    public static array $log = [];

    public static ?Throwable $failure = null;

    public ?string $conversationId = null;

    public static function forChatMessage(ChatMessage $message): static
    {
        return new static;
    }

    public function continue(string $conversationId, ?object $as = null): static
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    public function prompt(string $text): void
    {
        if (static::$failure !== null) {
            throw static::$failure;
        }

        $store = app(ConversationStore::class);

        $store->storeUserMessage($this->conversationId, null, null, static::class, new UserMessage($text));

        static::$log[] = [
            'text' => $text,
            'history' => $store->getLatestConversationMessages($this->conversationId, 10)->map(fn ($message) => $message->content)->all(),
        ];
    }
}
