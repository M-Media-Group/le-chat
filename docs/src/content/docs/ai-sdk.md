---
title: Laravel AI SDK
description: Store Laravel AI SDK conversations in Le Chat chatrooms, with the agent as a regular participant.
---

## Introduction
By default, the [Laravel AI SDK](https://laravel.com/docs/ai-sdk) remembers agent conversations in its own `agent_conversations` and `agent_conversation_messages` tables. Le Chat can replace that storage so an AI conversation is just another chatroom:

| AI SDK                               | Le Chat                                                              |
| ------------------------------------ | -------------------------------------------------------------------- |
| Conversation (`agent_conversations`) | `Chatroom`                                                           |
| Conversation owner                   | `ChatParticipant` with role `admin`                                  |
| Agent                                | Model-less `ChatParticipant` (see [Agents](#agents-as-participants)) |
| User message                         | `ChatMessage` sent by the owner                                      |
| Assistant message                    | `ChatMessage` sent by the agent                                      |

Only the storage driver is swapped. Agents, prompting, streaming, tools and tool approvals work exactly as before, and everything Le Chat offers (REST API, broadcasting, unread counts, events) works on AI chatrooms too. You can add other people to the same chatroom.

The feature is opt-in. When it is off, the AI SDK uses its own tables.

## Installation
Install the AI SDK, then publish and run the Le Chat migrations. They add a `metadata` column to `chat_messages` and make a participant's model optional.

```bash
composer require laravel/ai
php artisan vendor:publish --tag="le-chat-migrations"
php artisan migrate
```

You do not need the AI SDK's own conversation migration when this feature is enabled.

## Enabling
```php
// config/chat.php
'ai' => [
    'enabled' => env('CHAT_AI_ENABLED', false),
],
```

When enabled, Le Chat rebinds `Laravel\Ai\Contracts\ConversationStore`. Nothing changes in your agents:

```php
$response = (new SupportAgent)->forUser($user)->prompt('Hello!');

$response->conversationId; // pass this to continue() later
```

The owner you pass to `forUser()` / `forParticipant()` must be a model that uses the `IsChatParticipant` trait (see [Configuring Models](/configuring-models)).

## How data is mapped

### Conversations become chatrooms
| `agent_conversations` | Chatroom                                |
| --------------------- | --------------------------------------- |
| `id`                  | `metadata.ai.conversation_id`           |
| `title`               | `name`                                  |
| `participant_type/id` | A `ChatParticipant` with role `admin`   |
| `created_at`          | `created_at`                            |
| `updated_at`          | `updated_at` (touched on every message) |

### Messages become chat messages
| `agent_conversation_messages` | ChatMessage                                                                       |
| ----------------------------- | --------------------------------------------------------------------------------- |
| `conversation_id`             | `chatroom_id`                                                                     |
| `participant_type/id`         | `sender_id` for user turns                                                        |
| `agent`                       | `sender_id` (the agent participant) for assistant turns, and `metadata.ai.agent`  |
| `role`                        | `metadata.ai.role`                                                                |
| `content`                     | `message`                                                                         |
| `attachments`                 | `metadata.ai.attachments`                                                         |
| `steps`                       | `metadata.ai.steps`                                                               |
| `usage`                       | `metadata.ai.usage`                                                               |
| `meta`                        | `metadata.ai.meta`                                                                |
| `status`                      | `metadata.ai.status`                                                              |

Anything the AI SDK stores that Le Chat has no column for lives in the `metadata` JSON under the `ai` key, so it does not collide with your own metadata.

```php
$message->metadata['ai']['usage'];
$message->metadata['ai']['status']; // completed, paused, failed
```

The `message` column follows [encryption at rest](/package-configuration#encryption-of-messages-at-rest) like any other message. The `metadata` column is not encrypted.

## Conversation IDs
The AI SDK uses string IDs (UUIDs) and may generate one before anything is stored, so the SDK's ID is kept in `metadata.ai.conversation_id` and used to find the chatroom.

You can also pass a plain chatroom ID to continue a chatroom that was **not** created by the AI SDK. The agent joins as a participant and replies in it:

```php
(new SupportAgent)->continue((string) $chatroom->id, as: $user)->prompt('Summarise the discussion.');
```

The model receives the history visible to that agent participant: messages created after it joined, including system notices, are passed as user messages. The message that triggered an automatic response is passed as the current prompt and excluded from replayed history.

## Agents answer chatroom messages
Once an agent is a participant in a chatroom, it answers whenever a person posts there, not only when your code prompts it. A queued listener (`PromptAgentsOnMessageCreated`) runs on `MessageCreated`, continues the chatroom's conversation with each agent, and prompts it with the message. The reply is posted as the agent.

- The message that triggered the run is reused as the turn's user message, so it is not stored or shown to the model twice.
- Agent replies, messages the AI SDK wrote and system notices never trigger an agent, so agents cannot loop.
- When a tool is paused for approval, additional chat messages do not prompt the agent until the pending decision is resolved. The approval notice exposes the original paused AI metadata as `meta.approval_required.ai` and the normalized actions as `meta.approval_required.approvals`; consumers should submit decisions through the AI SDK's `Decisions` API.
- If all queue attempts fail, Le Chat posts a generic system message asking the user to try again. Provider error details stay in the server logs.
- Every agent in the chatroom currently answers every message. A per-participant option, such as only answering when tagged, is planned.

To turn this off and only prompt agents from your own code:

```php
// config/chat.php
'ai' => [
    'enabled' => env('CHAT_AI_ENABLED', false),
    'auto_respond' => false,
],
```

### Building the agent
Le Chat has to create the agent to prompt it. If its constructor can be resolved by the container, nothing else is needed. If it needs something else (such as the signed-in user), implement `RespondsToChatMessages` and build it from the message:

```php
use Mmedia\LeChat\Contracts\RespondsToChatMessages;
use Mmedia\LeChat\Models\ChatMessage;

class SupportAgent implements Agent, Conversational, RespondsToChatMessages
{
    use Promptable, RemembersConversations;

    public function __construct(public User $actor) {}

    public static function forChatMessage(ChatMessage $message): static
    {
        // The sender is a ChatParticipant; its model is the person who posted
        return new static($message->sender->participatingModel);
    }
}
```

The agent must use `RemembersConversations`, and it only answers in chatrooms where it is already a participant (it is added the first time it is prompted in one, or add it yourself with a `ChatParticipant` as described [below](#agents-as-participants)).

## Agents as participants
An agent does not need a model. It is a `ChatParticipant` with no `participant_type` / `participant_id`, identified by its `reference_id`:

| Column         | Value                                 |
| -------------- | ------------------------------------- |
| `reference_id` | `ai-agent:App\Ai\Agents\SupportAgent` |
| `display_name` | The agent's class basename            |

It is added the first time the agent is used in a chatroom. Change `display_name` or `avatar_url` on the participant row to customise how it appears. Because it has no model, an agent is never sent notifications.

## What your application must do
- Install `laravel/ai` and run the Le Chat migrations (see [Installation](#installation)). If you published the Le Chat migrations earlier, publish again and add only the new ones.
- Set `chat.ai.enabled` to `true`.
- Use the `IsChatParticipant` trait on every model you pass to `forUser()`, `forParticipant()` or `continue(..., as:)`.
- Run a queue worker. Auto-responses run in a queued listener, so with a sync queue the person's request waits for the model.
- Implement `RespondsToChatMessages` on agents the container cannot construct on its own.
- Don't call the AI SDK's `HasConversations` relation or `Laravel\Ai\Models\Conversation` for these conversations: they read the SDK's own tables. Query `Chatroom` instead.
- Agents are shown in the chat like any participant. Give them a nicer `display_name` and `avatar_url` if your UI shows them.

## Limitations
- Conversations with no owner have no admin participant. Their user turns are stored as messages with no sender.
- Existing rows in `agent_conversations` are not migrated. Conversations stored one way are not visible to the SDK when you switch to the other.
- The store builds on the AI SDK's `DatabaseConversationStore`, so it is tested against the installed `laravel/ai` version.
