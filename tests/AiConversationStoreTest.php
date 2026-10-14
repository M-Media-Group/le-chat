<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Mmedia\LeChat\Ai\ChatroomConversationStore;
use Mmedia\LeChat\Ai\RequesterEnvelope;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\LeChatServiceProvider;
use Mmedia\LeChat\Listeners\PromptAgentsOnMessageCreated;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

beforeEach(function () {
    if (! interface_exists(ConversationStore::class)) {
        $this->markTestSkipped('laravel/ai is not installed.');
    }

    require_once __DIR__.'/Stubs/AiStoreStubs.php';

    Schema::create('ai_store_owners', function (Blueprint $table): void {
        $table->id();
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
