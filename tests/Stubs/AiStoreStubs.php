<?php

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Models\ChatMessage;

use function Pest\Laravel\mock;

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

class AiStoreEchoAgent implements RemembersConversationsContract, RespondsToChatMessages
{
    use Promptable, RemembersConversations;

    /** @var list<array{text: string, history: list<string>}> */
    public static array $log = [];

    public static ?Throwable $failure = null;

    public static array $attachments = [];

    public static array $historyAttachments = [];

    public static function forChatMessage(ChatMessage $message): static
    {
        return new static;
    }

    public function prompt(string $text, array $attachments = []): void
    {
        if (static::$failure !== null) {
            throw static::$failure;
        }

        $store = app(ConversationStore::class);

        $store->storeUserMessage($this->conversationId, null, null, static::class, new UserMessage($text, $attachments));
        static::$attachments = $attachments;

        $history = $store->getLatestConversationMessages($this->conversationId, 10);
        static::$historyAttachments = $history->filter(fn ($message) => $message instanceof UserMessage)
            ->flatMap(fn (UserMessage $message) => $message->attachments)->all();
        static::$log[] = [
            'text' => $text,
            'history' => $history->map(fn ($message) => $message->content)->all(),
        ];
    }
}

final class AiStoreApprovalAgent implements Agent, RemembersConversationsContract, RespondsToChatMessages
{
    use Promptable, RemembersConversations;

    /** @var list<array{requester: Model, decisions: Decisions}> */
    public static array $resumeLog = [];

    public static ?int $pauseAgainMessageId = null;

    public function instructions(): string
    {
        return 'Be helpful.';
    }

    public static function forChatMessage(ChatMessage $message): static
    {
        return new self;
    }

    public function prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null): AgentResponse
    {
        if ($prompt instanceof Decisions) {
            if (! $this->conversationUser instanceof Model) {
                throw new LogicException('Expected an Eloquent requester.');
            }
            self::$resumeLog[] = ['requester' => $this->conversationUser, 'decisions' => $prompt];

            if (self::$pauseAgainMessageId !== null) {
                $message = ChatMessage::findOrFail(self::$pauseAgainMessageId);
                $metadata = $message->metadata->getArrayCopy();
                $metadata['ai']['steps'][0]['tool_calls'][0] = ['id' => 'call-2', 'name' => 'second_tool', 'arguments' => [], 'approval_reason' => 'Review again'];
                $message->forceFill(['metadata' => $metadata])->saveQuietly();
                $response = AgentResponse::fakeWithPendingApprovals([
                    new PendingApproval('call-2', 'second_tool', [], 'Review again'),
                ]);
                $response->assistantMessageId = (string) $message->id;

                return $response;
            }

            return new AgentResponse('resumed', 'Done', new TextUsage, new Meta);
        }
        if (! is_string($prompt)) {
            throw new InvalidArgumentException('Expected a text prompt.');
        }
        $store = app(ConversationStore::class);
        $store->storeUserMessage($this->conversationId, null, null, self::class, new UserMessage($prompt));
        $response = AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-1', 'example_tool', ['target' => 'private'], 'Review'),
        ]);
        $response->toolCalls->push(new ToolCall('call-1', 'example_tool', ['target' => 'private']));
        mock(TextProvider::class);
        $agentPrompt = new AgentPrompt($this, $prompt, [], app(TextProvider::class), 'model');
        $response->assistantMessageId = $store->storeAssistantMessage($this->conversationId, null, null, $agentPrompt, $response);

        return $response;
    }

    /** @return list<array{requester: Model, decisions: Decisions}> */
    public static function resumptions(): array
    {
        return self::$resumeLog;
    }
}

class AiStoreNativeApprovalTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public static array $executions = [];

    public function __construct(private Model $requester) {}

    public function description(): string
    {
        return 'Execute an approved test operation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['target' => $schema->string()->required()];
    }

    public function handle(Request $request): string
    {
        self::$executions[] = ['requester' => $this->requester->getKey(), 'target' => $request['target']];

        return 'Done';
    }
}

final class AiStoreNativeApprovalAgent implements Agent, Conversational, HasTools, RespondsToChatMessages
{
    use Promptable, RemembersConversations;

    public function __construct(private Model $requester) {}

    public static function forChatMessage(ChatMessage $message): static
    {
        return new self($message->sender->participatingModel);
    }

    public function instructions(): string
    {
        return 'Be helpful.';
    }

    public function tools(): iterable
    {
        return [new AiStoreNativeApprovalTool($this->requester)];
    }
}
