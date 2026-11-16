<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Ai;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Files\Base64Document;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\StoredVideo;
use Laravel\Ai\Gateway\FakeTextGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Mmedia\LeChat\Ai\ApprovalBatches;
use Mmedia\LeChat\Ai\ChatAttachments;
use Mmedia\LeChat\Ai\ChatroomConversationStore;
use Mmedia\LeChat\Ai\DelegatedAgent;
use Mmedia\LeChat\Ai\DelegatedRuns;
use Mmedia\LeChat\Ai\DelegatingAgentTool;
use Mmedia\LeChat\Ai\DelegationTools;
use Mmedia\LeChat\Ai\RequesterEnvelope;
use Mmedia\LeChat\Contracts\ApprovalPolicy;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\LeChatServiceProvider;
use Mmedia\LeChat\Listeners\PromptAgentsOnMessageCreated;
use Mmedia\LeChat\Models\ChatApprovalBatch;
use Mmedia\LeChat\Models\ChatFile;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    ChatMessage::flushMessageMode();
    if (! interface_exists(ConversationStore::class)) {
        $this->markTestSkipped('laravel/ai is not installed.');
    }

    require_once __DIR__.'/Stubs/AiStoreStubs.php';

    Schema::create('ai_store_owners', function (Blueprint $table): void {
        $table->id();
        $table->boolean('active')->default(true);
        $table->string('role')->default('manager');
        $table->timestamps();
    });

    AiStoreOwner::create();
    app()->instance(ChatroomConversationStore::class, new ChatroomConversationStore);
});

function aiStoreTestOwner(): Model
{
    return AiStoreOwner::query()->firstOrFail();
}

function aiStoreTestStore(): ChatroomConversationStore
{
    return app(ChatroomConversationStore::class);
}

function aiStoreTurn(string $text): array
{
    $prompt = new AgentPrompt(new AiStoreAgent, 'Hello', [], Mockery::mock(TextProvider::class), 'model');
    $response = new AgentResponse('invocation', $text, new TextUsage(3, 5), new Meta('openai', 'model'));

    return [$prompt, $response];
}

function aiStoreChatFile(ChatParticipant $sender): ChatFile
{
    Storage::disk('local')->put('chat-files/example', 'private contents');

    return ChatFile::create([
        'owner_type' => $sender->getMorphClass(), 'owner_id' => $sender->getKey(),
        'disk' => 'local', 'path' => 'chat-files/example', 'name' => 'example.bin',
        'mime_type' => 'application/octet-stream', 'size' => 16,
        'sha256' => hash('sha256', 'private contents'), 'status' => 'ready', 'expires_at' => now()->addDay(),
    ]);
}

it('maps chat files to private disk-backed AI attachments', function (string $mimeType, string $expectedClass) {
    Storage::fake('private-files');
    Storage::disk('private-files')->put('chat-files/example', 'content');
    $file = new ChatFile(['disk' => 'private-files', 'path' => 'chat-files/example', 'name' => 'example', 'mime_type' => $mimeType, 'status' => 'ready']);

    $attachment = ChatAttachments::fromFiles([$file])[0];

    expect($attachment)->toBeInstanceOf($expectedClass)
        ->and($attachment->disk)->toBe('private-files')
        ->and($attachment->path)->toBe('chat-files/example')
        ->and($attachment->name())->toBe('example')
        ->and($attachment->mimeType())->toBe($mimeType);
})->with([
    ['image/png', StoredImage::class],
    ['audio/mpeg', StoredAudio::class],
    ['video/mp4', StoredVideo::class],
    ['application/octet-stream', StoredDocument::class],
]);

it('does not silently omit unavailable chat attachments', function (string $status, bool $exists) {
    Storage::fake('private-files');
    if ($exists) {
        Storage::disk('private-files')->put('chat-files/example', 'content');
    }
    $file = new ChatFile(['disk' => 'private-files', 'path' => 'chat-files/example', 'name' => 'example', 'mime_type' => 'application/octet-stream', 'status' => $status]);

    expect(fn () => ChatAttachments::fromFiles([$file]))->toThrow(RuntimeException::class, 'A chat attachment is unavailable.');
})->with([
    ['ready', false],
    ['deleting', true],
]);

it('stores a conversation as a chatroom owned by an admin participant', function () {
    $id = aiStoreTestStore()->storeConversation(aiStoreTestOwner()->getMorphClass(), aiStoreTestOwner()->getKey(), 'Greeting');

    $room = Chatroom::sole();

    expect($room->name)->toBe('Greeting')
        ->and($room->metadata['ai']['conversation_id'])->toBe($id)
        ->and($room->participants()->where('role', 'admin')->where('participant_id', aiStoreTestOwner()->getKey())->exists())->toBeTrue()
        ->and(aiStoreTestStore()->conversationBelongsTo($id, aiStoreTestOwner()->getMorphClass(), aiStoreTestOwner()->getKey()))->toBeTrue()
        ->and(aiStoreTestStore()->conversationBelongsTo($id, aiStoreTestOwner()->getMorphClass(), 999))->toBeFalse();
});

it('stores user and assistant turns as chat messages with the extra fields in metadata', function () {
    $type = aiStoreTestOwner()->getMorphClass();
    $id = aiStoreTestStore()->storeConversation($type, aiStoreTestOwner()->getKey(), 'Greeting');

    aiStoreTestStore()->storeUserMessage($id, $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Hello'));
    [$prompt, $response] = aiStoreTurn('Hi there');
    $assistantId = aiStoreTestStore()->storeAssistantMessage($id, $type, aiStoreTestOwner()->getKey(), $prompt, $response);

    $messages = ChatMessage::orderBy('id')->get()->filter(fn ($message) => $message->metadata !== null)->values();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->message)->toBe('Hello')
        ->and($messages[0]->sender->participant_id)->toBe(aiStoreTestOwner()->getKey())
        ->and($messages[1]->message)->toBe('Hi there')
        ->and($messages[1]->sender->reference_id)->toBe('ai-agent:'.AiStoreAgent::class)
        ->and($messages[1]->metadata['ai']['usage']['input_tokens'])->toBe(3)
        ->and($messages[1]->metadata['ai']['status'])->toBe('completed')
        ->and($assistantId)->toBe((string) $messages[1]->getKey());
});

it('replays stored messages to the model in order', function () {
    config(['chat.can_see_messages_before_joined' => true]);
    $type = aiStoreTestOwner()->getMorphClass();
    $id = aiStoreTestStore()->storeConversation($type, aiStoreTestOwner()->getKey(), 'Greeting');

    aiStoreTestStore()->storeUserMessage($id, $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Hello'));
    [$prompt, $response] = aiStoreTurn('Hi there');
    aiStoreTestStore()->storeAssistantMessage($id, $type, aiStoreTestOwner()->getKey(), $prompt, $response);

    $agentParticipant = ChatParticipant::query()->where('reference_id', 'ai-agent:'.AiStoreAgent::class)->sole();

    expect($agentParticipant->visibleMessages()->get()->pluck('message')->all())->toContain('Hello', 'Hi there');

    $messages = aiStoreTestStore()->getLatestConversationMessages($id, 10);

    expect($messages->map(fn ($message) => [$message->role->value ?? $message->role, $message->content])->all())
        ->toBe([
            ['user', 'Owner has joined the chat.'],
            ['user', 'AiStoreAgent has joined the chat.'],
            ['user', '<earlier from="Owner">Hello</earlier>'],
            ['assistant', 'Hi there'],
        ]);
});

it('replays visible chat files as AI attachments without storing paths in metadata', function () {
    Event::fake([MessageCreated::class]);
    Storage::fake('local');
    config(['chat.create_system_messages' => false]);
    $room = Chatroom::create(['name' => 'Files']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreEchoAgent::class]);
    $file = aiStoreChatFile($sender);
    $message = $room->sendMessageAs($sender, '', ['files' => [$file->id]]);
    $current = $room->sendMessageAs($sender, 'Use the file');

    $history = aiStoreTestStore()->answering($current, AiStoreEchoAgent::class,
        fn () => aiStoreTestStore()->getLatestConversationMessages((string) $room->id, 10));
    $fileTurns = $history->filter(fn ($turn) => $turn instanceof UserMessage && $turn->attachments->isNotEmpty())->values();

    expect($fileTurns)->toHaveCount(1)
        ->and($fileTurns[0]->attachments)->toHaveCount(1)
        ->and($fileTurns[0]->attachments[0])->toBeInstanceOf(StoredDocument::class)
        ->and($fileTurns[0]->attachments[0]->content())->toBe('private contents')
        ->and($message->fresh()->metadata)->toBeNull();
});

it('preserves native SDK attachments in conversation history', function () {
    $owner = aiStoreTestOwner();
    $conversation = aiStoreTestStore()->storeConversation($owner->getMorphClass(), $owner->getKey(), 'Files');
    aiStoreTestStore()->storeUserMessage($conversation, $owner->getMorphClass(), $owner->getKey(), AiStoreAgent::class,
        new UserMessage('Review this', [Document::fromString('native content', 'text/plain')]));

    $history = aiStoreTestStore()->getLatestConversationMessages($conversation, 10);
    $turn = $history->first(fn ($message) => $message instanceof UserMessage && $message->attachments->isNotEmpty());

    expect($turn)->not->toBeNull()
        ->and($turn->attachments)->toHaveCount(1)
        ->and($turn->attachments[0])->toBeInstanceOf(Base64Document::class)
        ->and($turn->attachments[0]->content())->toBe('native content');
});

it('prompts an agent with a file-only chat message', function () {
    Storage::fake('local');
    Event::forget(MessageCreated::class);
    AiStoreEchoAgent::$log = [];
    AiStoreEchoAgent::$attachments = [];
    AiStoreEchoAgent::$historyAttachments = [];
    app()->instance(ConversationStore::class, aiStoreTestStore());
    $room = Chatroom::create(['name' => 'Files']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreEchoAgent::class]);
    $file = aiStoreChatFile($sender);
    $message = $room->sendMessageAs($sender, '', ['files' => [$file->id]]);

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($message));

    expect(AiStoreEchoAgent::$log)->toHaveCount(1)
        ->and(AiStoreEchoAgent::$attachments)->toHaveCount(1)
        ->and(AiStoreEchoAgent::$attachments[0])->toBeInstanceOf(StoredDocument::class)
        ->and(AiStoreEchoAgent::$attachments[0]->content())->toBe('private contents')
        ->and(AiStoreEchoAgent::$historyAttachments)->toBe([])
        ->and($message->fresh()->message)->toBe('');
});

it('passes file attachments through the actual AI SDK prompt', function () {
    Storage::fake('local');
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    $received = [];
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway(function ($prompt, $attachments) use (&$received) {
        $received = $attachments->all();

        return 'Received';
    }));
    $room = Chatroom::create(['name' => 'Files']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreAgent::class]);
    $file = aiStoreChatFile($sender);
    $message = $room->sendMessageAs($sender, '', ['files' => [$file->id]]);

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($message));

    expect($received)->toHaveCount(1)
        ->and($received[0])->toBeInstanceOf(StoredDocument::class)
        ->and($received[0]->content())->toBe('private contents');
});

it('replays an earlier file when a later instruction prompts the agent', function () {
    Storage::fake('local');
    Event::forget(MessageCreated::class);
    AiStoreEchoAgent::$log = [];
    AiStoreEchoAgent::$historyAttachments = [];
    app()->instance(ConversationStore::class, aiStoreTestStore());
    $room = Chatroom::create(['name' => 'Files']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreEchoAgent::class]);
    $file = aiStoreChatFile($sender);
    $room->sendMessageAs($sender, '', ['files' => [$file->id]]);
    $instruction = $room->sendMessageAs($sender, 'Use the earlier file');

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($instruction));

    expect(AiStoreEchoAgent::$log)->toHaveCount(1)
        ->and(AiStoreEchoAgent::$historyAttachments)->toHaveCount(1)
        ->and(AiStoreEchoAgent::$historyAttachments[0]->content())->toBe('private contents');
});

it('does not send a queued file message to an agent that cannot see it', function () {
    Storage::fake('local');
    Event::forget(MessageCreated::class);
    AiStoreEchoAgent::$log = [];
    app()->instance(ConversationStore::class, aiStoreTestStore());
    $room = Chatroom::create(['name' => 'Files']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreEchoAgent::class]);
    $file = aiStoreChatFile($sender);
    $message = $room->sendMessageAs($sender, '', ['files' => [$file->id]]);
    $agent->forceFill(['created_at' => now()->addMinute()])->saveQuietly();

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($message));

    expect(AiStoreEchoAgent::$log)->toBe([]);
});

it('does not replay files the current requester cannot see', function () {
    Storage::fake('local');
    Event::forget(MessageCreated::class);
    AiStoreEchoAgent::$historyAttachments = [];
    app()->instance(ConversationStore::class, aiStoreTestStore());
    $room = Chatroom::create(['name' => 'Files']);
    $originalSender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'original']);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreEchoAgent::class]);
    $originalSender->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();
    $agent->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();
    $file = aiStoreChatFile($originalSender);
    $earlierMessage = $room->sendMessageAs($originalSender, '', ['files' => [$file->id]]);
    $earlierMessage->forceFill(['created_at' => now()->subMinutes(5)])->saveQuietly();
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'requester']);
    $instruction = $room->sendMessageAs($requester, 'Use the earlier file');

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($instruction));

    expect(AiStoreEchoAgent::$historyAttachments)->toBe([]);
});

it('reports when an agent cannot process a file message', function () {
    Storage::fake('local');
    Event::forget(MessageCreated::class);
    $room = Chatroom::create(['name' => 'Files']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $file = aiStoreChatFile($sender);
    $message = $room->sendMessageAs($sender, '', ['files' => [$file->id]]);

    (new PromptAgentsOnMessageCreated)->failed(new MessageCreated($message), new RuntimeException('provider secret'));

    expect($room->messages()->latest('id')->firstOrFail()->message)
        ->toBe("Sorry, I couldn't process that message or its attachments. Please try again.");
});

it('reports attachment processing failures for later instructions', function () {
    Storage::fake('local');
    Event::forget(MessageCreated::class);
    $room = Chatroom::create(['name' => 'Files']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $file = aiStoreChatFile($sender);
    $room->sendMessageAs($sender, '', ['files' => [$file->id]]);
    $instruction = $room->sendMessageAs($sender, 'Use the earlier file');

    (new PromptAgentsOnMessageCreated)->failed(new MessageCreated($instruction), new RuntimeException('provider secret'));

    expect($room->messages()->latest('id')->firstOrFail()->message)
        ->toBe("Sorry, I couldn't process that message or its attachments. Please try again.");
});

it('reconstructs completed tool turns through the SDK store helpers', function () {
    $type = aiStoreTestOwner()->getMorphClass();
    $id = aiStoreTestStore()->storeConversation($type, aiStoreTestOwner()->getKey(), 'Tool turn');
    aiStoreTestStore()->storeUserMessage($id, $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Run a tool'));

    $room = Chatroom::query()->where('metadata->ai->conversation_id', $id)->firstOrFail();
    $agentParticipant = $room->participants()->where('reference_id', 'ai-agent:'.AiStoreAgent::class)->firstOrFail();

    $room->sendMessageAs($agentParticipant, 'Tool response', ['metadata' => ['ai' => [
        'role' => 'assistant',
        'agent' => AiStoreAgent::class,
        'meta' => ['provider' => 'provider-a', 'model' => 'test-model'],
        'status' => 'completed',
        'steps' => [[
            'content' => 'Tool response',
            'reasoning' => '',
            'tool_calls' => [[
                'id' => 'call-1',
                'name' => 'example_tool',
                'arguments' => [],
                'result' => 'done',
            ]],
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]],
    ]]]);

    $assistantMessage = aiStoreTestStore()->getLatestConversationMessages($id, 10)->first(fn ($message) => $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty());

    expect($assistantMessage)->toBeInstanceOf(AssistantMessage::class);

    if (! $assistantMessage instanceof AssistantMessage) {
        throw new UnexpectedValueException('Expected an assistant tool-call message.');
    }

    expect($assistantMessage->toolCalls->first()->id)->toBe('call-1')
        ->and(aiStoreTestStore()->getLatestConversationMessages($id, 10)->last())->toBeInstanceOf(ToolResultMessage::class);
});

it('starts bounded provider history at a user turn when it cuts off a tool turn', function () {
    $type = aiStoreTestOwner()->getMorphClass();
    $id = aiStoreTestStore()->storeConversation($type, aiStoreTestOwner()->getKey(), 'Bounded tool history');

    aiStoreTestStore()->storeUserMessage($id, $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Earlier request'));

    $room = Chatroom::query()->where('metadata->ai->conversation_id', $id)->firstOrFail();
    $agentParticipant = $room->participants()->where('reference_id', 'ai-agent:'.AiStoreAgent::class)->firstOrFail();

    $room->sendMessageAs($agentParticipant, 'Tool result', ['metadata' => ['ai' => [
        'role' => 'assistant',
        'agent' => AiStoreAgent::class,
        'meta' => ['provider' => 'gemini', 'model' => 'test-model'],
        'status' => 'completed',
        'steps' => [[
            'content' => '',
            'tool_calls' => [[
                'id' => 'call-1',
                'name' => 'example_tool',
                'arguments' => [],
                'result' => 'done',
            ]],
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]],
    ]]]);

    aiStoreTestStore()->storeUserMessage($id, $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Latest request'));

    $messages = aiStoreTestStore()->getLatestConversationMessages($id, 2);

    expect($messages->map(fn ($message) => $message->role->value)->all())->toBe(['user'])
        ->and($messages->first()->content)->toContain('Latest request');
});

it('collapses older completed tool history only for the configured agent during automatic responses', function () {
    $owner = aiStoreTestOwner();
    $type = $owner->getMorphClass();
    $store = aiStoreTestStore();
    $id = $store->storeConversation($type, $owner->getKey(), 'Collapsed tool history');

    $store->storeUserMessage($id, $type, $owner->getKey(), AiStoreAgent::class, new UserMessage('First request'));

    $room = Chatroom::query()->where('metadata->ai->conversation_id', $id)->firstOrFail();
    $agentParticipant = $room->participants()->where('reference_id', 'ai-agent:'.AiStoreAgent::class)->firstOrFail();
    $storeAssistantTurn = fn (string $content, string $callId): ChatMessage => $room->sendMessageAs($agentParticipant, $content, ['metadata' => ['ai' => [
        'role' => 'assistant',
        'agent' => AiStoreAgent::class,
        'meta' => ['provider' => 'provider-a', 'model' => 'test-model'],
        'status' => 'completed',
        'steps' => [[
            'content' => $content,
            'tool_calls' => [[
                'id' => $callId,
                'name' => 'example_tool',
                'arguments' => [],
                'result' => $content === '' ? 'blank result' : $content,
            ]],
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]],
    ]]]);

    $olderTurn = $storeAssistantTurn('Earlier tool result', 'older-call');
    $store->storeUserMessage($id, $type, $owner->getKey(), AiStoreAgent::class, new UserMessage('Second request'));
    $blankTurn = $storeAssistantTurn('', 'blank-call');
    $store->storeUserMessage($id, $type, $owner->getKey(), AiStoreAgent::class, new UserMessage('Third request'));
    $storeAssistantTurn('Latest tool result', 'latest-call');
    $currentId = $store->storeUserMessage($id, $type, $owner->getKey(), AiStoreAgent::class, new UserMessage('Current request'));
    $current = ChatMessage::findOrFail($currentId);
    $callIds = fn ($messages): array => $messages
        ->filter(fn ($message) => $message instanceof AssistantMessage)
        ->flatMap(fn (AssistantMessage $message) => $message->toolCalls)
        ->pluck('id')->all();

    config(['chat-options.ai-sdk-integration.collapse_tool_history' => [AiStoreAgent::class]]);

    $manualHistory = $store->getLatestConversationMessages($id, 20);
    expect($callIds($manualHistory))->toContain('older-call')->toContain('blank-call');

    $otherAgentHistory = $store->answering($current, AiStoreEchoAgent::class,
        fn () => $store->getLatestConversationMessages($id, 20));
    expect($callIds($otherAgentHistory))->toContain('older-call');

    $automaticHistory = $store->answering($current, AiStoreAgent::class,
        fn () => $store->getLatestConversationMessages($id, 20));
    $olderAssistant = $automaticHistory->first(fn ($message) => $message instanceof AssistantMessage && $message->content === 'Earlier tool result');
    $latestAssistant = $automaticHistory->first(fn ($message) => $message instanceof AssistantMessage && $message->content === 'Latest tool result');

    expect($olderAssistant)->toBeInstanceOf(AssistantMessage::class);
    expect($olderAssistant->toolCalls)->toBeEmpty();
    expect($latestAssistant)->toBeInstanceOf(AssistantMessage::class);
    expect($latestAssistant->toolCalls->first()->id)->toBe('latest-call');
    expect($callIds($automaticHistory))->not->toContain('older-call')->not->toContain('blank-call');
    expect($olderTurn->fresh()->metadata['ai']['steps'][0]['tool_calls'][0]['id'])->toBe('older-call')
        ->and($blankTurn->fresh()->metadata['ai']['steps'][0]['tool_calls'][0]['id'])->toBe('blank-call');
});

it('stops listing an approval after its tool call is resolved', function () {
    $type = aiStoreTestOwner()->getMorphClass();
    $id = aiStoreTestStore()->storeConversation($type, aiStoreTestOwner()->getKey(), 'Resolved approval');

    aiStoreTestStore()->storeUserMessage($id, $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Remove an attribute'));

    $room = Chatroom::query()->where('metadata->ai->conversation_id', $id)->firstOrFail();
    $agentParticipant = $room->participants()->where('reference_id', 'ai-agent:'.AiStoreAgent::class)->firstOrFail();

    $room->sendMessageAs($agentParticipant, '', ['metadata' => ['ai' => [
        'role' => 'assistant',
        'agent' => AiStoreAgent::class,
        'meta' => ['provider' => 'provider-a', 'model' => 'test-model'],
        'status' => 'paused',
        'steps' => [[
            'content' => '',
            'tool_calls' => [[
                'id' => 'pending-call',
                'name' => 'example_tool',
                'arguments' => [],
                'approval_reason' => 'Needs approval.',
            ]],
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ]],
    ]]]);

    expect(aiStoreTestStore()->pendingApprovalsFor($id))->toHaveCount(1);

    aiStoreTestStore()->storeApprovalResults($id, [new ToolResult('pending-call', 'example_tool', [], 'done')]);

    expect(aiStoreTestStore()->pendingApprovalsFor($id))->toBe([]);
});

it('uses the default policy and binds an approval batch to the original tool calls', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => ['secret' => 'private'], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);

    $batch = app(ApprovalBatches::class)->create($request, $paused);

    expect($batch->status)->toBe('pending')
        ->and($batch->requester_message_id)->toBe($request->id)
        ->and($batch->fingerprints)->toHaveKey('call-1')
        ->and(json_encode($batch->toArray()))->not->toContain('private')
        ->and($paused->fresh()->blocks[0]['block_id'])->toBe('approval-'.$batch->id)
        ->and(json_encode($paused->fresh()->blocks))->not->toContain('private');
});

it('records a failed requester reauthorization without running a tool again on retry', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => ['target' => 'original'], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): string
        {
            return 'Requester session expired.';
        }
    });
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);
    $decisions = ['call-1' => ['action' => 'approve']];
    Event::fake([MessageUpdated::class]);

    $lock = Cache::lock('laravel-queue-overlap:le-chat-ai:'.$room->id, 60);
    expect($lock->get())->toBeTrue();
    try {
        $pending = $service->decide($batch, $requester, $decisions, 'request-1');
        expect($pending->status)->toBe('pending');
    } finally {
        $lock->release();
    }

    $result = $service->decide($batch, $requester, $decisions, 'request-1');
    $retry = $service->decide($batch->fresh(), $requester, $decisions, 'request-1');

    expect($result->status)->toBe('cancelled')
        ->and($result->failure_reason)->toBe('Requester session expired.')
        ->and($result->idempotency_key)->toBe('request-1')
        ->and($result->approver_id)->toBe($requester->id)
        ->and($retry->status)->toBe('cancelled');
    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event): bool => $event->message->approvalBatch?->status === 'cancelled');
});

it('does not resume when the requester is deactivated or loses their role', function (array $changes) {
    Event::fake([MessageCreated::class]);
    AiStoreApprovalAgent::$resumeLog = [];
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool|string
        {
            $owner = $requester->sender->participatingModel?->fresh();

            return $owner !== null && $owner->active && $owner->role === 'manager' ? true : 'Requester is no longer authorized.';
        }
    });
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);
    aiStoreTestOwner()->update($changes);

    $result = $service->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1');

    expect($result->status)->toBe('cancelled')
        ->and($result->failure_reason)->toBe('Requester is no longer authorized.')
        ->and(AiStoreApprovalAgent::$resumeLog)->toBe([]);
})->with([
    'deactivated' => [['active' => false]],
    'role revoked' => [['role' => 'viewer']],
]);

it('projects approval blocks when an agent pauses', function () {
    Event::fake([MessageCreated::class]);
    config(['chat.features' => [Features::aiSdkIntegration()]]);
    Schema::drop('chat_message_files');
    Schema::drop('chat_files');
    app()->instance(ConversationStore::class, aiStoreTestStore());
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return false;
        }
    });
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));

    $batch = ChatApprovalBatch::sole();
    $paused = ChatMessage::findOrFail($batch->assistant_message_id);
    expect($batch->requester_message_id)->toBe($request->id)
        ->and($paused->blocks[0]['block_id'])->toBe('approval-'.$batch->id)
        ->and(json_encode($paused->blocks))->not->toContain('private');
});

it('encrypts approval blocks and their fallback text', function () {
    Event::fake([MessageCreated::class]);
    config(['chat.features' => [Features::encryptMessagesAtRest()]]);
    ChatMessage::flushMessageMode();
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return false;
        }
    });

    app(ApprovalBatches::class)->create($request, $paused);

    $raw = DB::table('chat_messages')->where('id', $paused->id)->first();
    expect($raw->message)->not->toContain('Tool approval required')
        ->and($raw->blocks)->not->toContain('Tool approval required')
        ->and($paused->fresh()->message)->toContain('Tool approval required')
        ->and($paused->fresh()->blocks[0]['text'])->toBe('Tool approval required');
});

it('resumes approved tools as the requester rather than the approver', function () {
    Event::fake([MessageCreated::class]);
    AiStoreApprovalAgent::$resumeLog = [];
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $approver = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'approver']);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => ['target' => 'original'], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);

    $result = $service->decide($batch, $approver, ['call-1' => ['action' => 'approve']], 'request-1');
    $resumption = AiStoreApprovalAgent::resumptions()[0] ?? throw new LogicException('Expected one resumption.');

    expect($result->status)->toBe('resumed')
        ->and($result->approver_id)->toBe($approver->id)
        ->and(AiStoreApprovalAgent::$resumeLog)->toHaveCount(1)
        ->and($resumption['requester']->getKey())->toBe(aiStoreTestOwner()->getKey())
        ->and($resumption['decisions']->get('call-1')->isApproved())->toBeTrue();
});

it('preserves runtime tool overrides and restores them after a Le Chat invocation', function (bool $fails) {
    $child = new AiStoreStatelessApprovalAgent(aiStoreTestOwner());
    $agent = new AiStoreNativeApprovalAgent(aiStoreTestOwner());
    $resolutions = 0;
    $agent->withTools(function (array $declared) use ($child, &$resolutions): array {
        $resolutions++;

        return [...$declared, $child];
    });
    $invoke = function () use ($agent, $child, $fails, &$resolutions): void {
        DelegationTools::within($agent, function () use ($agent, $child, $fails, &$resolutions): void {
            $tools = DelegationTools::forAgent($agent);
            expect($tools[0])->toBeInstanceOf(AiStoreNativeApprovalTool::class);
            expect($tools[1])->toBeInstanceOf(DelegatingAgentTool::class)
                ->and($tools[1]->agent())->toBe($child)
                ->and(DelegationTools::forAgent($agent))->toBe($tools)
                ->and($resolutions)->toBe(1);
            $nested = DelegatedAgent::wrap($agent, [], 'root/parent')->tools();
            expect($nested[1])->toBeInstanceOf(DelegatingAgentTool::class)
                ->and($nested[1]->agent())->toBe($child);

            if ($fails) {
                throw new RuntimeException('Invocation failed');
            }
        });
    };

    if ($fails) {
        expect($invoke)->toThrow(RuntimeException::class, 'Invocation failed');
    } else {
        $invoke();
    }

    expect(DelegationTools::forAgent($agent)[1])->toBe($child)
        ->and($resolutions)->toBe(2);
})->with([false, true]);

it('leaves normal SDK subagents untouched when AI integration is disabled', function () {
    config(['chat.features' => []]);
    $agent = new AiStoreDelegatingAgent(aiStoreTestOwner());

    $tools = DelegationTools::within($agent, fn () => DelegationTools::forAgent($agent));

    expect($tools[0])->toBeInstanceOf(AiStoreNativeApprovalAgent::class);
});

it('normalizes deferred subagents without mutating their original tool search', function () {
    $child = new AiStoreStatelessApprovalAgent(aiStoreTestOwner());
    $search = new ToolSearch([new AgentTool($child)], 'bm25');
    $agent = (new AiStoreNativeApprovalAgent(aiStoreTestOwner()))->withTools([$search]);

    DelegationTools::within($agent, function () use ($agent, $search, $child): void {
        $tools = DelegationTools::forAgent($agent);
        expect($tools[0])->not->toBe($search)
            ->and($tools[0]->strategy)->toBe('bm25')
            ->and($tools[0]->tools[0])->toBeInstanceOf(DelegatingAgentTool::class)
            ->and(DelegationTools::delegates($tools)[0]->agent())->toBe($child);
    });

    expect(DelegationTools::forAgent($agent)[0])->toBe($search)
        ->and($search->tools[0])->not->toBeInstanceOf(DelegatingAgentTool::class);
});

it('prepares a delegated approval without executing the action or creating a chatroom', function () {
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('child-call', 'AiStoreNativeApprovalTool', ['target' => 'original']),
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $tool = new DelegatingAgentTool((new AiStoreNativeApprovalAgent(aiStoreTestOwner()))->forUser(aiStoreTestOwner()));

    $approval = $tool->shouldRequestApproval(new Request(['task' => 'Run the original action'], 'parent-call'));

    expect($approval?->reason)->toContain('AiStoreNativeApprovalTool', 'original')
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([])
        ->and(Chatroom::count())->toBe(0);
});

it('checkpoints delegated state when optional step metadata is absent', function (array $ai) {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('child-call', 'AiStoreNativeApprovalTool', ['target' => 'original']),
    ]));
    $room = Chatroom::create(['name' => 'Checkpoint']);
    $assistant = $room->sendMessage('Pending', ['metadata' => ['ai' => $ai, 'custom' => 'preserved']]);
    app(DelegatedRuns::class)->load($assistant);
    $tool = new DelegatingAgentTool(new AiStoreNativeApprovalAgent(aiStoreTestOwner()));

    $approval = $tool->shouldRequestApproval(new Request(['task' => 'Run original'], 'parent-call'));

    $metadata = $assistant->fresh()->metadata->getArrayCopy();
    expect($approval?->reason)->toContain('AiStoreNativeApprovalTool', 'original')
        ->and($metadata['ai']['delegations']['root/parent-call']['status'])->toBe('paused')
        ->and($metadata['custom'])->toBe('preserved');
    unset($metadata['ai']['delegations']);
    expect($metadata['ai'])->toBe($ai);
})->with([
    'missing steps' => [[]],
    'null steps' => [['steps' => null]],
    'missing tool calls' => [['steps' => [['content' => 'Earlier message']]]],
    'null tool calls' => [['steps' => [['content' => 'Earlier message', 'tool_calls' => null]]]],
]);

it('returns a read-only delegated result without creating a chatroom', function () {
    app()->register(AiServiceProvider::class);
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway(['Internal answer']));
    $tool = new DelegatingAgentTool((new AiStoreNativeApprovalAgent(aiStoreTestOwner()))->forUser(aiStoreTestOwner()));
    $request = new Request(['task' => 'Read only'], 'read-call');

    expect($tool->shouldRequestApproval($request))->toBeNull()
        ->and($tool->handle($request))->toBe('Internal answer')
        ->and(Chatroom::count())->toBe(0);

    $stream = $tool->stream($request);
    expect(iterator_to_array($stream))->toBe([])
        ->and($stream->getReturn())->toBe('Internal answer');
});

it('preserves the structured schema of an isolated child agent', function () {
    app()->register(AiServiceProvider::class);
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([]));
    $child = new class implements Agent, HasStructuredOutput
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Return a structured answer.';
        }

        public function schema(JsonSchema $schema): array
        {
            return ['answer' => $schema->string()->required()];
        }
    };
    $tool = new DelegatingAgentTool($child);
    $request = new Request(['task' => 'Answer'], 'structured-call');

    expect($tool->shouldRequestApproval($request))->toBeNull()
        ->and(json_decode($tool->handle($request), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('answer')
        ->and(Chatroom::count())->toBe(0);
});

it('does not execute a delegated action with an unauthorized or stale approval', function (string $scenario, string $exceptionClass) {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('parent-call', 'AiStoreNativeApprovalAgent', ['task' => 'Run original']),
        new ToolCall('child-call', 'AiStoreNativeApprovalTool', ['target' => 'original']),
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Delegation']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreDelegatingAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run original');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $batch = ChatApprovalBatch::sole();
    $actor = $requester;
    if ($scenario === 'unauthorized') {
        $actor = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'other-approver']);
    } elseif ($scenario === 'changed') {
        $assistant = $batch->assistantMessage;
        $metadata = $assistant->metadata->getArrayCopy();
        $metadata['ai']['delegations']['root/parent-call']['pending'][0]['arguments']['target'] = 'changed';
        $assistant->forceFill(['metadata' => $metadata])->saveQuietly();
    } else {
        $batch->update(['expires_at' => now()->subMinute()]);
    }
    app()->instance(DelegatedRuns::class, new DelegatedRuns);

    expect(fn () => app(ApprovalBatches::class)->decide($batch, $actor, ['parent-call' => ['action' => 'approve']], 'invalid'))->toThrow($exceptionClass);
    expect(AiStoreNativeApprovalTool::$executions)->toBe([]);
})->with([
    ['unauthorized', AuthorizationException::class],
    ['changed', ValidationException::class],
    ['expired', ValidationException::class],
]);

it('resumes a delegated approval from persisted child history without creating another room', function (string $parentClass, string $childClass) {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('parent-call', $childClass, ['task' => 'Run the original action']),
        new ToolCall('child-call', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        'Child completed', 'Parent completed',
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Delegation']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.$parentClass]);
    $request = $room->sendMessageAs($requester, 'Run a delegated tool');

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $batch = ChatApprovalBatch::sole();
    expect(AiStoreNativeApprovalTool::$executions)->toBe([])
        ->and($batch->assistantMessage->metadata['ai']['delegations']['root/parent-call']['pending'][0]['arguments'])->toBe(['target' => 'original']);
    app()->instance(DelegatedRuns::class, new DelegatedRuns);

    $result = app(ApprovalBatches::class)->decide($batch, $requester, ['parent-call' => ['action' => 'approve']], 'approve-delegation');

    expect($result->status)->toBe('resumed')
        ->and($result->failure_reason)->toBeNull()
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([['requester' => aiStoreTestOwner()->getKey(), 'target' => 'original']])
        ->and(Chatroom::count())->toBe(1)
        ->and($room->participants()->where('reference_id', 'like', 'ai-agent:%')->count())->toBe(1)
        ->and($batch->assistantMessage->fresh()->message)->toBe('Parent completed');
})->with([
    [AiStoreDelegatingAgent::class, AiStoreNativeApprovalAgent::class],
    [AiStoreStatelessDelegatingAgent::class, AiStoreStatelessApprovalAgent::class],
    [AiStoreRuntimeDelegatingAgent::class, AiStoreStatelessApprovalAgent::class],
]);

it('does not replay a delegated mutation after an interrupted child continuation', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('parent-call', 'AiStoreNativeApprovalAgent', ['task' => 'Run original']),
        new ToolCall('child-call', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        fn () => throw new RuntimeException('Provider disconnected after execution'),
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Interrupted delegation']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreDelegatingAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run original');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $batch = ChatApprovalBatch::sole();
    app()->instance(DelegatedRuns::class, new DelegatedRuns);

    $result = app(ApprovalBatches::class)->decide($batch, $requester, ['parent-call' => ['action' => 'approve']], 'interrupted');
    app(ApprovalBatches::class)->decide($result->fresh(), $requester, ['parent-call' => ['action' => 'approve']], 'interrupted');
    app()->instance(DelegatedRuns::class, new DelegatedRuns);
    app(DelegatedRuns::class)->load($batch->assistantMessage->fresh());
    $tool = new DelegatingAgentTool(new AiStoreNativeApprovalAgent(aiStoreTestOwner()));

    expect($result->failure_reason)->toContain('uncertain')
        ->and(fn () => $tool->advance(new Request(['task' => 'Run original'], 'parent-call')))->toThrow(LogicException::class, 'uncertain')
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([['requester' => aiStoreTestOwner()->getKey(), 'target' => 'original']]);
});

it('keeps the parent paused for a second child approval without replaying the first action', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('parent-call', 'AiStoreNativeApprovalAgent', ['task' => 'Run two actions']),
        new ToolCall('first-child-call', 'AiStoreNativeApprovalTool', ['target' => 'first']),
        new ToolCall('second-child-call', 'AiStoreNativeApprovalTool', ['target' => 'second']),
        'Child completed', 'Parent completed',
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Delegation']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreDelegatingAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run two delegated tools');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $first = ChatApprovalBatch::sole();
    app()->instance(DelegatedRuns::class, new DelegatedRuns);

    app(ApprovalBatches::class)->decide($first, $requester, ['parent-call' => ['action' => 'approve']], 'first');

    $second = ChatApprovalBatch::query()->where('generation', 2)->sole();
    expect($second->status)->toBe('pending')
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([['requester' => aiStoreTestOwner()->getKey(), 'target' => 'first']])
        ->and($second->assistantMessage->metadata['ai']['status'])->toBe('paused')
        ->and(app(ApprovalBatches::class)->details($second, $requester)[0]['reason'])->toContain('second');
    app(ApprovalBatches::class)->decide($first->fresh(), $requester, ['parent-call' => ['action' => 'approve']], 'first');
    app()->instance(DelegatedRuns::class, new DelegatedRuns);

    app(ApprovalBatches::class)->decide($second, $requester, ['parent-call' => ['action' => 'approve']], 'second');

    expect(AiStoreNativeApprovalTool::$executions)->toBe([
        ['requester' => aiStoreTestOwner()->getKey(), 'target' => 'first'],
        ['requester' => aiStoreTestOwner()->getKey(), 'target' => 'second'],
    ])->and($second->assistantMessage->fresh()->message)->toBe('Parent completed')
        ->and(Chatroom::count())->toBe(1);
});

it('resumes approvals through two levels of delegated conversational agents', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('parent-call', 'AiStoreDelegatingAgent', ['task' => 'Delegate again']),
        new ToolCall('middle-call', 'AiStoreNativeApprovalAgent', ['task' => 'Run original']),
        new ToolCall('child-call', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        'Child completed', 'Middle completed', 'Parent completed',
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Nested delegation']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNestedDelegatingAgent::class]);
    $request = $room->sendMessageAs($requester, 'Delegate twice');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $batch = ChatApprovalBatch::sole();
    app()->instance(DelegatedRuns::class, new DelegatedRuns);

    $result = app(ApprovalBatches::class)->decide($batch, $requester, ['parent-call' => ['action' => 'approve']], 'nested');

    expect($result->failure_reason)->toBeNull()
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([['requester' => aiStoreTestOwner()->getKey(), 'target' => 'original']])
        ->and($batch->assistantMessage->fresh()->message)->toBe('Parent completed')
        ->and(Chatroom::count())->toBe(1);
});

it('rejects delegated changes without executing the child action', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('parent-call', 'AiStoreNativeApprovalAgent', ['task' => 'Run original']),
        new ToolCall('child-call', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        'No changes made',
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Rejected delegation']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreDelegatingAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run original');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $batch = ChatApprovalBatch::sole();
    app()->instance(DelegatedRuns::class, new DelegatedRuns);

    $result = app(ApprovalBatches::class)->decide($batch, $requester, ['parent-call' => ['action' => 'reject']], 'reject');

    expect($result->failure_reason)->toBeNull()
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([])
        ->and($batch->assistantMessage->fresh()->message)->toBe('No changes made')
        ->and(Chatroom::count())->toBe(1);
});

it('pauses and resumes a real SDK tool approval as the requester', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('call-1', 'AiStoreNativeApprovalTool', ['target' => 'original']), 'Done',
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $approver = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'approver']);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNativeApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    expect(AiStoreNativeApprovalTool::$executions)->toBe([]);
    $batch = ChatApprovalBatch::sole();
    $service = app(ApprovalBatches::class);
    expect(fn () => $service->details($batch, $approver))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->decide($batch, $approver, ['call-1' => ['action' => 'approve']], 'other-request'))->toThrow(AuthorizationException::class);
    $result = $service->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1');

    expect($result->status)->toBe('resumed')
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([['requester' => aiStoreTestOwner()->getKey(), 'target' => 'original']])
        ->and(aiStoreTestStore()->pendingApprovalsFor(aiStoreTestStore()->conversationIdOf($room)))->toBe([]);
});

it('replaces a pending approval when its requester sends a correction', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('call-1', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        new ToolCall('call-2', 'AiStoreNativeApprovalTool', ['target' => 'changed']),
        'Done',
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNativeApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run the original action');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $original = ChatApprovalBatch::sole();
    $correction = $room->sendMessageAs($requester, 'Actually use the changed target');

    expect(fn () => app(ApprovalBatches::class)->decide($original, $requester, ['call-1' => ['action' => 'approve']], 'stale'))
        ->toThrow(ConflictHttpException::class);
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($correction));

    $replacement = ChatApprovalBatch::query()->where('requester_message_id', $correction->id)->firstOrFail();
    $oldAssistant = $original->assistantMessage->fresh();
    expect($original->fresh()->status)->toBe('cancelled')
        ->and($oldAssistant->metadata['ai']['steps'][0]['tool_calls'][0]['denied'])->toBeTrue()
        ->and($oldAssistant->message)->toContain('cancelled', 'No action was performed')
        ->and($replacement->status)->toBe('pending')
        ->and($replacement->fingerprints)->toHaveKey('call-2')
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([]);
    expect(fn () => app(ApprovalBatches::class)->decide($original->fresh(), $requester, ['call-1' => ['action' => 'approve']], 'old'))
        ->toThrow(ConflictHttpException::class);

    app(ApprovalBatches::class)->decide($replacement, $requester, ['call-2' => ['action' => 'approve']], 'new');
    expect(AiStoreNativeApprovalTool::$executions)->toBe([['requester' => aiStoreTestOwner()->getKey(), 'target' => 'changed']]);
});

it('allows only the requester to replace an unclaimed approval', function (string $status) {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('call-1', 'AiStoreNativeApprovalTool', ['target' => 'original']),
    ]));
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $otherOwner = AiStoreOwner::create();
    $other = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => $otherOwner->getMorphClass(), 'participant_id' => $otherOwner->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNativeApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $batch = ChatApprovalBatch::sole();

    $otherMessage = $room->sendMessageAs($other, 'Change it');
    expect(app(ApprovalBatches::class)->supersedeWith($otherMessage))->toBeFalse();
    $batch->update(['status' => $status, 'idempotency_key' => 'reserved']);
    $ownMessage = $room->sendMessageAs($requester, 'Change it');
    expect(app(ApprovalBatches::class)->supersedeWith($ownMessage))->toBeTrue()
        ->and($batch->fresh()->status)->toBe('cancelled');
})->with(['pending', 'expired']);

it('stops an unchanged failed action without requesting another approval', function (bool $throws, string $expectedFailure) {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('call-1', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        new ToolCall('call-2', 'AiStoreNativeApprovalTool', ['target' => 'original']),
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    AiStoreNativeApprovalTool::$result = '{"error":"Private validation error"}';
    AiStoreNativeApprovalTool::$fail = $throws;
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNativeApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');

    try {
        (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
        $batch = ChatApprovalBatch::sole();
        $result = app(ApprovalBatches::class)->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1');
    } finally {
        AiStoreNativeApprovalTool::$result = 'Done';
        AiStoreNativeApprovalTool::$fail = false;
    }

    $assistant = ChatMessage::findOrFail($batch->assistant_message_id);
    expect($result->status)->toBe('resumed')
        ->and($result->failure_reason)->toContain($expectedFailure)
        ->and(AiStoreNativeApprovalTool::$executions)->toHaveCount(1)
        ->and(ChatApprovalBatch::count())->toBe(1)
        ->and(aiStoreTestStore()->pendingApprovalsFor(aiStoreTestStore()->conversationIdOf($room)))->toBe([])
        ->and($assistant->metadata['ai']['status'])->toBe('completed')
        ->and($assistant->message)->toContain('previous action failed', 'retry was stopped')
        ->and($assistant->message)->not->toContain($expectedFailure)
        ->and($assistant->blocks)->toBeNull();
})->with([[false, 'Private validation error'], [true, 'Private runtime error']]);

it('explains a renewed approval after an action fails with different arguments', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('call-1', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        new ToolCall('call-2', 'AiStoreNativeApprovalTool', ['target' => 'changed']),
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    AiStoreNativeApprovalTool::$result = '{"error":"Private validation error"}';
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNativeApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');

    try {
        (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
        $batch = ChatApprovalBatch::sole();
        app(ApprovalBatches::class)->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1');
    } finally {
        AiStoreNativeApprovalTool::$result = 'Done';
    }

    $assistant = ChatMessage::findOrFail($batch->assistant_message_id);
    $details = app(ApprovalBatches::class)->details($assistant->approvalBatch, $requester);
    expect(AiStoreNativeApprovalTool::$executions)->toHaveCount(1)
        ->and(ChatApprovalBatch::count())->toBe(2)
        ->and($assistant->approvalBatch->fingerprints)->toHaveKey('call-2')
        ->and($details[0]['reason'])->toContain('Previous attempt failed: Private validation error')
        ->and($assistant->blocks[0]['text'])->toContain('previous action failed')
        ->and($assistant->message)->toContain('previous action failed')
        ->and($assistant->message)->not->toContain('Private validation error');
});

it('keeps changed calls pending when a batch also repeats a failed action', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('call-1', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call-2', 'AiStoreNativeApprovalTool', ['target' => 'original'], 'Review'),
            new PendingApproval('call-3', 'AiStoreNativeApprovalTool', ['target' => 'changed'], 'Review'),
        ]),
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    AiStoreNativeApprovalTool::$result = '{"error":"Validation failed"}';
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNativeApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run tools');

    try {
        (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
        $batch = ChatApprovalBatch::sole();
        app(ApprovalBatches::class)->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1');
    } finally {
        AiStoreNativeApprovalTool::$result = 'Done';
    }

    $assistant = ChatMessage::findOrFail($batch->assistant_message_id);
    expect(AiStoreNativeApprovalTool::$executions)->toHaveCount(1)
        ->and(array_keys($assistant->approvalBatch->fingerprints))->toBe(['call-3'])
        ->and($assistant->metadata['ai']['status'])->toBe('paused')
        ->and($assistant->metadata['ai']['steps'][1]['tool_calls'][0]['denied'])->toBeTrue();
});

it('does not suppress another approval after a successful action', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('call-1', 'AiStoreNativeApprovalTool', ['target' => 'original']),
        new ToolCall('call-2', 'AiStoreNativeApprovalTool', ['target' => 'original']),
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNativeApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $batch = ChatApprovalBatch::sole();
    app(ApprovalBatches::class)->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1');

    $assistant = ChatMessage::findOrFail($batch->assistant_message_id);
    expect(AiStoreNativeApprovalTool::$executions)->toHaveCount(1)
        ->and($assistant->approvalBatch->fingerprints)->toHaveKey('call-2')
        ->and($assistant->blocks[0]['text'])->toContain('another action')
        ->and($assistant->blocks[0]['text'])->not->toContain('failed');
});

it('preserves an application approval policy registered before the package', function () {
    $policy = new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return false;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return false;
        }
    };
    app()->instance(ApprovalPolicy::class, $policy);

    (new LeChatServiceProvider($this->app))->register();

    expect(app(ApprovalPolicy::class))->toBe($policy);
});

it('records an explicit rejection in the real SDK approval loop', function () {
    Event::fake([MessageCreated::class]);
    app()->register(AiServiceProvider::class);
    app()->instance(ConversationStore::class, aiStoreTestStore());
    config(['ai.default' => 'openai', 'ai.providers.openai' => ['driver' => 'openai', 'key' => 'fake', 'models' => ['text' => ['default' => 'test-model']]]]);
    Ai::textProvider('openai')->useTextGateway(new FakeTextGateway([
        new ToolCall('call-1', 'AiStoreNativeApprovalTool', ['target' => 'original']), 'Not executed',
    ]));
    AiStoreNativeApprovalTool::$executions = [];
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreNativeApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });

    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($request));
    $batch = ChatApprovalBatch::sole();
    $result = app(ApprovalBatches::class)->decide($batch, $requester, ['call-1' => ['action' => 'reject', 'result' => 'Declined']], 'request-1');
    $storedCall = ChatMessage::findOrFail($batch->assistant_message_id)->metadata['ai']['steps'][0]['tool_calls'][0];

    expect($result->status)->toBe('resumed')
        ->and(AiStoreNativeApprovalTool::$executions)->toBe([])
        ->and($storedCall['denied'])->toBeTrue()
        ->and($storedCall['result'])->toBe('Declined');
});

it('accepts a complete mixed decision batch and treats reordered retries as idempotent', function () {
    Event::fake([MessageCreated::class]);
    AiStoreApprovalAgent::$resumeLog = [];
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run tools');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [
            ['id' => 'call-1', 'name' => 'first_tool', 'arguments' => [], 'approval_reason' => 'Review'],
            ['id' => 'call-2', 'name' => 'second_tool', 'arguments' => [], 'approval_reason' => 'Review'],
        ], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);
    expect(fn () => $service->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'incomplete'))
        ->toThrow(ValidationException::class);
    $decisions = ['call-1' => ['action' => 'approve'], 'call-2' => ['action' => 'reject', 'result' => 'Declined']];

    $result = $service->decide($batch, $requester, $decisions, 'request-1');
    $retry = $service->decide($batch->fresh(), $requester, array_reverse($decisions, true), 'request-1');
    $resumption = AiStoreApprovalAgent::resumptions()[0] ?? throw new LogicException('Expected one resumption.');

    expect($result->status)->toBe('resumed')->and($retry->status)->toBe('resumed')
        ->and(AiStoreApprovalAgent::$resumeLog)->toHaveCount(1)
        ->and($resumption['decisions']->get('call-1')->isApproved())->toBeTrue()
        ->and($resumption['decisions']->get('call-2')->result)->toBe('Declined');
});

it('allows only one approval when two decisions interleave', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $other = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'other']);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    $policy = new class implements ApprovalPolicy
    {
        public ?Closure $beforeClaim = null;

        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            if ($this->beforeClaim !== null) {
                $callback = $this->beforeClaim;
                $this->beforeClaim = null;
                $callback();
            }

            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return false;
        }
    };
    app()->instance(ApprovalPolicy::class, $policy);
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);
    $policy->beforeClaim = fn () => $service->decide($batch->fresh(), $other, ['call-1' => ['action' => 'reject']], 'other-request');

    expect(fn () => $service->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'first-request'))
        ->toThrow(ConflictHttpException::class);
    expect($batch->fresh()->approver_id)->toBe($other->id)
        ->and($batch->fresh()->status)->toBe('cancelled');
});

it('rechecks approver authorization immediately before claiming a batch', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    $policy = new class implements ApprovalPolicy
    {
        public int $calls = 0;

        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return ++$this->calls === 1;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    };
    app()->instance(ApprovalPolicy::class, $policy);
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);

    expect(fn () => $service->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1'))
        ->toThrow(AuthorizationException::class);
    expect($batch->fresh()->status)->toBe('pending');
});

it('rejects approval of modified tool arguments', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => ['target' => 'original'], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);
    $metadata = $paused->fresh()->metadata->getArrayCopy();
    $metadata['ai']['steps'][0]['tool_calls'][0]['arguments']['target'] = 'changed';
    $paused->fresh()->forceFill(['metadata' => $metadata])->saveQuietly();

    expect(fn () => $service->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1'))
        ->toThrow(ValidationException::class);
    expect($batch->fresh()->status)->toBe('pending');
});

it('rejects duplicate pending tool call identifiers', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run tools');
    $tool = ['id' => 'duplicate', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review'];
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [$tool, $tool], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });

    expect(fn () => app(ApprovalBatches::class)->create($request, $paused))->toThrow(LogicException::class);
    expect(ChatApprovalBatch::count())->toBe(0);
});

it('expires approval batches without executing the tool', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);
    $batch->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $service->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1'))
        ->toThrow(ValidationException::class);
    expect($batch->fresh()->status)->toBe('expired');
});

it('marks abandoned approval batches expired', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });
    $batch = app(ApprovalBatches::class)->create($request, $paused);
    $batch->update(['expires_at' => now()->subMinute()]);
    Event::fake([MessageUpdated::class]);

    $this->artisan('le-chat:expire-approvals')->assertSuccessful();

    expect($batch->fresh()->status)->toBe('expired');
    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event): bool => $event->message->approvalBatch?->status === 'expired');
});

it('cancels a pending approval when its requester message is deleted', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run a tool');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });
    $batch = app(ApprovalBatches::class)->create($request, $paused);

    $request->delete();

    expect($batch->fresh()->status)->toBe('cancelled')
        ->and($batch->fresh()->failure_reason)->not->toBeNull();
});

it('creates a new approval batch when the same assistant turn pauses again', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run tools');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'first_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });
    $service = app(ApprovalBatches::class);
    $first = $service->create($request, $paused);
    $first->update(['status' => 'resumed']);
    $metadata = $paused->fresh()->metadata->getArrayCopy();
    $metadata['ai']['steps'][0]['tool_calls'][0] = ['id' => 'call-2', 'name' => 'second_tool', 'arguments' => [], 'approval_reason' => 'Review again'];
    $paused->fresh()->forceFill(['metadata' => $metadata])->saveQuietly();

    $second = $service->create($request, $paused->fresh());

    expect($second->id)->not->toBe($first->id)
        ->and($second->fingerprints)->toHaveKey('call-2')
        ->and($paused->fresh()->approvalBatch->id)->toBe($second->id);
});

it('projects another approval if the resumed agent pauses again', function () {
    Event::fake([MessageCreated::class]);
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = ChatParticipant::create(['chatroom_id' => $room->id, 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:'.AiStoreApprovalAgent::class]);
    $request = $room->sendMessageAs($requester, 'Run tools');
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => AiStoreApprovalAgent::class, 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'first_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return true;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return true;
        }
    });
    $service = app(ApprovalBatches::class);
    $batch = $service->create($request, $paused);
    AiStoreApprovalAgent::$pauseAgainMessageId = $paused->id;

    try {
        $service->decide($batch, $requester, ['call-1' => ['action' => 'approve']], 'request-1');
    } finally {
        AiStoreApprovalAgent::$pauseAgainMessageId = null;
    }

    expect($batch->fresh()->status)->toBe('resumed')
        ->and($paused->fresh()->approvalBatch->fingerprints)->toHaveKey('call-2');
});

it('retains opaque provider replay data when storing completed turns', function () {
    $type = aiStoreTestOwner()->getMorphClass();
    $id = aiStoreTestStore()->storeConversation($type, aiStoreTestOwner()->getKey(), 'Tool turn');
    aiStoreTestStore()->storeUserMessage($id, $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Run a tool'));

    $prompt = new AgentPrompt(new AiStoreAgent, 'Run a tool', [], Mockery::mock(TextProvider::class), 'thinking-model');
    $response = new AgentResponse('invocation', 'Tool response', new TextUsage, new Meta('provider-a', 'test-model'));
    $response->steps->push(new Step(
        'Tool response',
        [new ToolCall('call-1', 'example_tool', [], thoughtSignature: 'signature-1')],
        [],
        FinishReason::ToolCalls,
        new TextUsage,
        new Meta('provider-a', 'test-model'),
        'Opaque state',
        [
            ['type' => 'provider_state', 'value' => 'opaque-state'],
        ],
    ));

    aiStoreTestStore()->storeAssistantMessage($id, $type, aiStoreTestOwner()->getKey(), $prompt, $response);

    $room = Chatroom::query()->where('metadata->ai->conversation_id', $id)->firstOrFail();
    $assistantMessage = $room->messages()->where('metadata->ai->role', 'assistant')->firstOrFail();

    expect($assistantMessage->metadata['ai']['steps'][0]['replay_blocks'])->toBe([
        ['type' => 'provider_state', 'value' => 'opaque-state'],
    ]);
});

it('finds the latest conversation for an owner and agent, and can continue a plain chatroom by id', function () {
    $this->freezeTime();
    $type = aiStoreTestOwner()->getMorphClass();
    $id = aiStoreTestStore()->storeConversation($type, aiStoreTestOwner()->getKey(), 'Greeting');
    aiStoreTestStore()->storeUserMessage($id, $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Hello'));

    expect(aiStoreTestStore()->latestConversationId($type, aiStoreTestOwner()->getKey(), AiStoreAgent::class))->toBe($id);

    $plain = Chatroom::create(['name' => 'Existing']);
    $plain->sendMessage('system notice');

    aiStoreTestStore()->storeUserMessage((string) $plain->getKey(), $type, aiStoreTestOwner()->getKey(), AiStoreAgent::class, new UserMessage('Join in'));

    expect(aiStoreTestStore()->getLatestConversationMessages((string) $plain->getKey(), 10))->toHaveCount(4);
});

it('only replaces the SDK store when enabled', function () {

    expect(Features::enabled(Features::aiSdkIntegration()))->toBeTrue(); // Enabled by default

    config(['chat.features' => []]);

    expect(Features::enabled(Features::aiSdkIntegration()))->toBeFalse();

    // The provider already booted with the feature enabled by default
    unset($this->app[ConversationStore::class]);

    expect(app()->bound(ConversationStore::class))->toBeFalse();

    config(['chat.features' => [Features::aiSdkIntegration()]]);

    expect(Features::enabled(Features::aiSdkIntegration()))->toBeTrue();

    (new LeChatServiceProvider($this->app))->bootingPackage();

    expect(app(ConversationStore::class))->toBeInstanceOf(ChatroomConversationStore::class);
});

it('prompts the agents in a chatroom when a person posts, and answers that message without duplicating it', function () {
    AiStoreEchoAgent::$log = [];
    app()->instance(ConversationStore::class, aiStoreTestStore());

    // Drop the auto_respond listener so the handler only runs when invoked below
    Event::forget(MessageCreated::class);

    $room = Chatroom::create(['name' => 'Room']);
    $person = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    ChatMessage::query()->where('chatroom_id', $room->getKey())->update(['created_at' => now()->subMinute()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'ai-agent:'.AiStoreEchoAgent::class, 'display_name' => 'Echo']);

    $message = $room->sendMessageAs($person, 'Hello agent');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($message));

    expect(AiStoreEchoAgent::$log)->toBe([['text' => RequesterEnvelope::current('Owner', 'Hello agent'), 'history' => [
        'Echo has joined the chat.',
    ]]])
        ->and(ChatMessage::whereNotNull('metadata->ai->role')->count())->toBe(1)
        ->and($message->fresh()->metadata['ai']['role'])->toBe('user');

    // Neither the agent's own messages nor SDK-written messages trigger another run
    $reply = $room->sendMessageAs($agent, 'Hi human');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($reply));
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($message->fresh()));

    expect(AiStoreEchoAgent::$log)->toHaveCount(1);
});

it('waits for pending tool approvals before sending another prompt', function () {
    AiStoreEchoAgent::$log = [];
    app()->instance(ConversationStore::class, aiStoreTestStore());

    $room = Chatroom::create(['name' => 'Room']);
    $person = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $agent = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'ai-agent:'.AiStoreEchoAgent::class, 'display_name' => 'Echo']);

    $originalAiMetadata = [
        'role' => 'assistant',
        'agent' => AiStoreEchoAgent::class,
        'meta' => ['provider' => 'provider-a'],
        'status' => 'paused',
        'steps' => [[
            'content' => '',
            'tool_calls' => [[
                'id' => 'pending-call',
                'name' => 'example_tool',
                'arguments' => [],
                'approval_reason' => 'Needs approval.',
                'thought_signature' => 'signature',
            ]],
            'replay_blocks' => [],
        ]],
    ];

    $room->sendMessageAs($agent, '', ['metadata' => ['ai' => $originalAiMetadata]]);

    $userMessage = $room->sendMessageAs($person, 'Nevermind');
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($userMessage));

    $notice = $room->messages()->latest('id')->first();

    expect(aiStoreTestStore()->pendingApprovalsFor((string) $room->getKey()))->toHaveCount(1)
        ->and(AiStoreEchoAgent::$log)->toBe([])
        ->and($notice->message)->toBe('The assistant needs your approval before it can continue.')
        ->and($notice->metadata['approval_required']['type'])->toBe('approval_required')
        ->and($notice->metadata['approval_required']['ai'])->toBe($originalAiMetadata)
        ->and($notice->metadata['approval_required']['approvals'])->toBe([[
            'id' => 'pending-call',
            'tool' => 'example_tool',
            'arguments' => [],
            'reason' => 'Needs approval.',
        ]]);
});

it('posts a generic chatroom message when the AI listener finally fails', function () {
    app()->instance(ConversationStore::class, aiStoreTestStore());

    $room = Chatroom::create(['name' => 'Room']);
    $person = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'participant_type' => aiStoreTestOwner()->getMorphClass(), 'participant_id' => aiStoreTestOwner()->getKey()]);
    $userMessage = $room->sendMessageAs($person, 'Please help');

    (new PromptAgentsOnMessageCreated)->failed(
        new MessageCreated($userMessage),
        new RuntimeException('Provider secret detail')
    );

    $failureMessage = $room->messages()->latest('id')->firstOrFail();

    expect($failureMessage->message)->toBe("Sorry, I couldn't respond just now. Please try again shortly.")
        ->and($failureMessage->sender_id)->toBeNull()
        ->and($failureMessage->message)->not->toContain('Provider secret detail');

    $messageCount = $room->messages()->count();
    (new PromptAgentsOnMessageCreated)->handle(new MessageCreated($failureMessage));

    expect($room->messages()->count())->toBe($messageCount);
});

it('serializes queued assistant responses by chatroom', function () {
    $room = Chatroom::create(['name' => 'Room']);
    $message = $room->sendMessage('Hello');

    $middleware = (new PromptAgentsOnMessageCreated)->middleware(new MessageCreated($message));

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe('le-chat-ai:'.$room->getKey())
        ->and($middleware[0]->releaseAfter)->toBe(30)
        ->and($middleware[0]->expiresAfter)->toBe(600);
});
