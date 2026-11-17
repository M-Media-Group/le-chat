---
title: Laravel AI SDK
description: Store Laravel AI SDK conversations in Le Chat chatrooms, with the agent as a regular participant.
---

## Introduction
Le Chat can store Laravel AI SDK conversations in chatrooms. This lets your agents use the SDK's conversation APIs while their messages appear in Le Chat like messages from other participants.

By default, the [Laravel AI SDK](https://laravel.com/docs/ai-sdk) remembers agent conversations in its own `agent_conversations` and `agent_conversation_messages` tables. Le Chat can replace that storage so an AI conversation is just another chatroom:

| AI SDK                               | Le Chat                                                              |
| ------------------------------------ | -------------------------------------------------------------------- |
| Conversation (`agent_conversations`) | `Chatroom`                                                           |
| Conversation owner                   | `ChatParticipant` with role `admin`                                  |
| Agent                                | Model-less `ChatParticipant` (see [Agents](#agents-as-participants)) |
| User message                         | `ChatMessage` sent by the owner                                      |
| Assistant message                    | `ChatMessage` sent by the agent                                      |

Only conversation storage changes. The Laravel AI SDK's agent, prompt, streaming, and tool APIs stay the same. AI chatrooms also work with Le Chat's REST API, broadcasting, unread counts, and events. You can add other people to an AI chatroom.

## Installation
Install the AI SDK, then publish and run the Le Chat migrations. The chat tables include nullable JSON `metadata` columns for rooms, participants, and messages. A participant's underlying application model is optional, so agents can participate without a user record.

```bash
composer require laravel/ai
php artisan vendor:publish --tag="le-chat-migrations"
php artisan migrate
```

You do not need the AI SDK's own conversation migration when this feature is enabled.

## Enabling
The package config enables this integration by default when `laravel/ai` is installed. Remove the feature entry to disable it.

```php
// config/chat.php
use Mmedia\LeChat\Features;

'features' => [
    Features::aiSdkIntegration([
        'auto_respond' => true,
        'typing_indicators' => true,
        'collapse_tool_history' => [],
    ]),
],
```


When enabled, Le Chat rebinds `Laravel\Ai\Contracts\ConversationStore`. Nothing changes in your agents:

```php
$response = (new SupportAgent)->forUser($user)->prompt('Hello!');

$response->conversationId; // pass this to continue() later
```

The owner you pass to `forUser()` / `forParticipant()` must be a model that uses the `IsChatParticipant` trait (see [Configuring Models](/configuring-models)).

## Tool approvals

When a tool requires approval, the AI SDK pauses before running it. An approver can review the pending tool details, then approve or reject every pending call in the batch. Approval is not a permission grant: after approval, the tool runs as the original requester and must still authorize that person to perform the action.

By default, only the original requester can view and decide an approval. Le Chat also checks that the message is visible, the requester is still available, the request has not expired, and the pending tool calls have not changed.

The default policy cannot check application-specific rules such as account activation, tenant membership, or session revocation. Each tool must authorize its requester against the target resource when it runs. If your application needs additional checks or wants a different approver, implement `Mmedia\LeChat\Contracts\ApprovalPolicy` and bind it in a service provider:

```php
use App\Ai\ChatApprovalPolicy;
use Mmedia\LeChat\Contracts\ApprovalPolicy;

$this->app->bind(ApprovalPolicy::class, ChatApprovalPolicy::class);
```

`mayApprove` decides who may review an approval. `mayResume` can enforce additional rules before the original requester is allowed to continue. It may return `true`, `false`, or a denial reason. Do not store session tokens in the approval record; retain only non-secret information needed to check whether a session is still valid.

Approval batches expire after 15 minutes by default. Change `chat.approvals.ttl_minutes` to adjust this. Retrying the same decision with the same idempotency key is safe; a conflicting decision is rejected. If a tool may have changed data before an error occurred, check the result before trying the action again. All application workers must use a shared cache store so simultaneous prompts and approval decisions for one chatroom do not overlap.

## Subagents

With `Features::aiSdkIntegration()` enabled, Le Chat automatically adapts subagents during chatroom auto-responses and approval resumes. Declare subagents using the normal SDK API:

```php
use App\Ai\Agents\AttributesAssistant;

public function tools(): iterable
{
    return [
        new AttributesAssistant($this->conversationUser),
    ];
}
```

Le Chat keeps the child task and history isolated, even if the child uses `RemembersConversations`. Every delegated tool says so in its description and task schema, including agents with a custom `CanActAsTool::description()`. The task is required and non-empty; include all context the child needs because Le Chat does not copy parent messages into child history. It does not create another chatroom, add another chat participant, or append internal child messages to the visible conversation. Nested agents and agents inside SDK tool-search groups are adapted recursively. Existing runtime tool overrides are preserved, and the original tool configuration is restored when the invocation ends, including on failure.

If a child implements `HasStructuredOutput`, its structured result is returned to the parent as JSON. Define any fields your application needs, such as `summary` and `evidence`; Le Chat passes the result through unchanged and does not interpret IDs or enforce application-specific rules.

A structured result is still generated by the child model. Preserving its fields does not prove that its claims match an executed tool or the current state of your application.

If the parent needs the actual results returned by child tools, opt in by implementing `Mmedia\LeChat\Contracts\IncludesDelegatedToolResults` on the child. The parent then receives a JSON object with `summary`, `tool_results`, and, when available, `structured`. `tool_results` contains the SDK's tool result payloads, including tool names, arguments, and returned values. Le Chat passes these through without interpreting IDs or applying application-specific rules. Tool arguments and results can contain sensitive data, so opt in only when the parent should receive them.

This automatic behavior applies only to Le Chat-owned invocations. Calling an agent's SDK `prompt()` or `stream()` directly does not pass through the chat listener and is not intercepted. `DelegatingAgentTool` remains available for explicitly adapted tools, but is not required in Le Chat-managed agent declarations. Native SDK delegation outside these flows still has the SDK's own approval and persistence behavior.

When a child pauses, the parent pauses with an approval describing the exact pending child tools and arguments. Approval covers only those listed actions, not the whole delegated task. If the child requests more approval afterward, a new approval batch is created and the parent remains paused. Rejecting a delegated call does not execute its pending child actions.

Continue using the chat approval routes, which resume the saved child execution before resuming the parent. Do not resume a delegated approval by calling the parent's SDK `prompt(Decisions::from(...))` directly. Pass the original requester into the child's tools so they can recheck authorization when executed. If a child continuation fails after a mutation may have run, the approval is marked uncertain and automatic replay is refused; reconcile the outcome before retrying.

Child state is stored in the parent assistant message's private `metadata.ai`, bound to the approval fingerprint. It is not exposed by message resources. The same metadata storage and encryption limitations described below apply.

## Internal storage

Each record owns its own data:

- The chatroom's `metadata.ai.conversation_id` identifies the shared SDK conversation.
- The participant's `metadata.mention_aliases` and `metadata.ai_response_mode` configure that participant.
- A message's private `metadata.ai` stores that turn's SDK replay data, including tool arguments, results, and provider state.

SDK replay data is an internal storage format, not a stable application API; do not build clients against it or expose it in message responses. Metadata is not encrypted by the message-encryption feature, so treat its contents accordingly.

Use the AI SDK's conversation methods to continue a conversation. Use the approval routes to retrieve pending tool details; raw provider replay data is not part of the message resource.

## Conversation IDs
Pass the conversation ID returned by the AI SDK to continue that conversation. Le Chat maps it to the chatroom internally; application code does not need to read message metadata.

You can also pass a plain chatroom ID to continue a chatroom that was **not** created by the AI SDK. The agent joins as a participant and replies in it:

```php
(new SupportAgent)->continue((string) $chatroom->id, as: $user)->prompt('Summarise the discussion.');
```

The model receives the history visible to that agent participant: messages created after it joined, including system notices, are passed as user messages. The message that triggered an automatic response is passed as the current prompt and excluded from replayed history.

When the SDK limits history, the limit counts stored chat messages, not user/assistant exchanges. Le Chat selects up to that many message rows, then trims leading messages until history starts at a user message. If the limit cuts through a turn, the provider may receive fewer messages than the requested limit.

## Agents answer chatroom messages
With `auto_respond` enabled, Le Chat can reply to chat messages using the agents in that room. You do not need to call `prompt()` for each incoming message. A queued listener (`PromptAgentsOnMessageCreated`) selects the agents that should answer, continues their conversations, and posts their replies in the room.

- Shared chat files are sent as AI SDK attachments, including on file-only messages and in conversation history visible to both the agent and the requester. The provider receives their contents; supported formats and size limits depend on the provider and model. A failed request produces a generic message without exposing provider details.
- The message that triggered the run is reused as the turn's user message, so it is not stored or shown to the model twice.
- Agent replies, messages the AI SDK wrote and system notices never trigger an agent, so agents cannot loop.
- When a tool is paused for approval, additional chat messages do not prompt the agent until the pending decision is resolved. The assistant message contains approval blocks and the batch status; authorized approvers can load the exact tool details and submit a complete decision batch through the [approval routes](/routing#approval-decisions). Raw provider replay data is not exposed in message resources.
- If all queue attempts fail, Le Chat posts a generic system message asking the user to try again. Provider error details stay in the server logs.
- Each agent has its own response mode. New agent participants default to `all`; use `mentions` to require an explicit mention, or `off` to stop automatic replies from that agent.

To keep the AI SDK integration but prompt agents only from your own code, disable automatic responses:

```php
// config/chat.php
'features' => [
    Features::aiSdkIntegration([
        'auto_respond' => false,
    ]),
],
```

### Choose when an agent responds

There are two separate controls:

- **The global switch** is `auto_respond` in `config/chat.php`. If it is `false`, no agent automatically answers chat messages, regardless of its individual settings.
- **The agent participant's response mode** decides when that participant answers. It only takes effect when the global switch is `true`. Human participants do not have an AI response mode.

These settings are checked in PHP **before the agent is constructed or the external AI provider is called**. An agent that is skipped does not incur a model request for that message. This is different from asking the model to stay quiet in its prompt: a prompt instruction alone cannot prevent a provider request.

#### 1. Add an agent to the room

Start with an existing `$chatroom` and an agent class such as `App\Ai\Agents\SupportAgent`. The agent must follow the [construction rules below](#building-the-agent). The people who will send messages must already be participants in the room.

Use `addAgent()` rather than creating a participant row or assembling a reference string yourself:

```php
use App\Ai\Agents\SupportAgent;

$agentParticipant = $chatroom->addAgent(SupportAgent::class);
$agentParticipant->setMentionAliases(['support']);
$agentParticipant->setAiResponseMode('all');

// Optional: choose the label people see in the chat.
$agentParticipant->update(['display_name' => 'Support assistant']);
```

`$agentParticipant` is the agent's `ChatParticipant` row, not an instance of `SupportAgent`. Adding an agent does not construct it or send a request to the model. Calling `addAgent(SupportAgent::class)` again returns the existing participant, restoring it if removed, without resetting its settings. New agent participants default to `all`.

`setMentionAliases()` accepts plain-text names without `@`. In this example, a person can mention the assistant by typing `@support`. Pass an empty list to clear its aliases.

#### 2. Choose a response mode

```php
$agentParticipant->setAiResponseMode('all');
$mode = $agentParticipant->aiResponseMode(); // 'all'
```

Call this on the participant returned by `addAgent()`. The argument is its response mode. Only `all`, `mentions`, and `off` are accepted. Changing the mode preserves its aliases and other metadata. Human and removed participants cannot be configured as active AI responders.

| Mode | Ordinary message with no mentions | Message mentioning this agent | Message mentioning somebody else |
| --- | --- | --- | --- |
| `all` | Replies | Replies | Does not reply |
| `mentions` | Does not reply | Replies | Does not reply |
| `off` | Does not reply | Does not reply | Does not reply |

If a message mentions several agents, each mentioned agent may answer unless its mode is `off`. Mentioning a human does not invoke unrelated AIs. Agents still need to be active participants and able to see the message.

Configure these values in trusted server code, such as your own service or AI handoff tool. Do not let guests write arbitrary participant settings. Aliases and response mode are stored in that participant's `metadata` column as `mention_aliases` and `ai_response_mode`. Use the participant methods so validation runs. Chatroom metadata does not store participant settings. The same agent class in another room has a separate participant with independent settings.

#### 3. Mention the agent

The stable mention format is `<@123>`, where `123` is the agent's **chat participant ID**. Get the correct token rather than assembling a user ID yourself:

```php
$token = $agentParticipant->mentionToken();

$user->sendMessageTo($chatroom, 'Can you help me, '.$token.'?');
```

Here, `$user` is a person already participating in `$chatroom`. If the agent participant's ID is `123`, the stored message contains `Can you help me, <@123>?`. Your frontend can display that token as `@Support assistant`; it must still send the original token to the backend. See [participant IDs and mentions](/participants#mentioning-a-participant) and [frontend integration](/messages#mentions-in-your-frontend).

The alias configured above also works:

```php
$user->sendMessageTo($chatroom, '@support, can you help me?');
```

Aliases are useful in SMS or WhatsApp messages, where a person cannot use your frontend's mention picker. They are case-insensitive and must be unique among active participants in the room. An alias starts with a letter and may contain letters, numbers, underscores, or hyphens, up to 32 characters. Pass `support`, not `@support`, when configuring it. Text such as `contact@support.example` or `@supportbot` does not mention the alias `support`.

Display names are not aliases. Naming a participant `Support assistant` does not automatically make `@Support assistant` a working mention.

#### 4. Hand the conversation to a human

When your application decides a human should take over, add that person to the room and switch the agent to `mentions`:

```php
// $supportUser is an existing chat-capable user your application allows to join.
$chatroom->addParticipant($supportUser);
$agentParticipant->setAiResponseMode('mentions');
```

The human can now answer. Ordinary messages no longer trigger this agent or a provider request. A person can still explicitly ask it for help with `<@123>` or `@support`. Le Chat does not decide when someone is frustrated or which employee should join; that decision belongs in your application or an agent tool.

The `support` alias from step 1 is preserved automatically. Mention aliases are separate from AI response policy. To change the aliases for any participant, human or AI, use `$agentParticipant->setMentionAliases(['support', 'help'])`. Pass an empty list to remove aliases; the ID token still works.

To stop automatic replies from this agent completely, including replies to mentions:

```php
$agentParticipant->setAiResponseMode('off');
```

This does not remove the agent or delete any messages. These modes control Le Chat's automatic response listener only. Your own direct calls to the SDK's `prompt()` or `stream()` still run the model.

#### If the agent does not answer

- Check that `auto_respond` is `true` and a queue worker is running.
- Check that the agent is still an active participant and its mode is not `off`.
- In `mentions` mode, use its `mention_token` or an explicitly configured alias, not a user ID or display name.
- If you mention another participant, unrelated agents will not answer even in `all` mode.
- Check [message visibility](/messages#message-visibility) and whether a tool is waiting for approval.

### Building the agent
Le Chat has to create the agent to prompt it. If its constructor can be resolved by the container, nothing else is needed. If it needs something else (such as the signed-in user), implement `RespondsToChatMessages` and build it from the message:

```php
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Models\ChatMessage;

class SupportAgent implements Agent, Conversational, RespondsToChatMessages
{
    use Promptable, RemembersConversations;

    public static function forChatMessage(ChatMessage $message): static
    {
        // The sender is a ChatParticipant; its model is the person who posted
        return new static($message->sender->participatingModel);
    }
}
```

The agent must use `RemembersConversations`, and it only answers in chatrooms where it is already a participant. It is added the first time it is prompted in one, or you can register it with `$chatroom->addAgent(SupportAgent::class)`.

## Agents as participants
An agent does not need a model. It is a `ChatParticipant` with no `participant_type` / `participant_id`, identified by its `reference_id`:

| Column         | Value                                 |
| -------------- | ------------------------------------- |
| `reference_id` | `ai-agent:App\Ai\Agents\SupportAgent` |
| `display_name` | The agent's class basename            |

It is added the first time the agent is used in a chatroom, or when your code calls `$chatroom->addAgent(SupportAgent::class)`. Use that room method instead of constructing the `reference_id` yourself. Change `display_name` or `avatar_url` on the returned participant row to customise how it appears. Because it has no model, an agent is never sent notifications.

## What your application must do
- Install `laravel/ai` and run the Le Chat migrations (see [Installation](#installation)). If you published the Le Chat migrations earlier, publish again and add only the new ones.
- Enable the `Features::aiSdkIntegration()` feature in `config/chat.php` if it is not already enabled.
- Use the `IsChatParticipant` trait on every model you pass to `forUser()`, `forParticipant()` or `continue(..., as:)`.
- If `auto_respond` is enabled, run a queue worker. Auto-responses run in a queued listener, so with a sync queue the person's request waits for the model.
- Implement `RespondsToChatMessages` on agents the container cannot construct on its own.
- Don't call the AI SDK's `HasConversations` relation or `Laravel\Ai\Models\Conversation` for these conversations: they read the SDK's own tables. Query `Chatroom` instead.
- Agents are shown in the chat like any participant. Give them a nicer `display_name` and `avatar_url` if your UI shows them.

## Limitations
- Conversations with no owner have no admin participant. Their user turns are stored as messages with no sender.
- Existing rows in `agent_conversations` are not migrated. Conversations stored one way are not visible to the SDK when you switch to the other.
- The store builds on the AI SDK's `DatabaseConversationStore`, so it is tested against the installed `laravel/ai` version.
