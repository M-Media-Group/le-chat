---
title: Messages
description: Documentation for managing chat messages in Le Chat.
---

## Introduction
Le Chat allows you to manage messages between chat participants. This includes sending, receiving, and storing messages in a chat application.

## Seeing messages
Messages are just another model in Le Chat, and you can retrieve them using the `ChatMessage` model.
```php
use \Mmedia\LeChat\Models\ChatMessage;

ChatMessage::get();
```
### Message visibility
Not all messages should be visible to all participants. Le Chat automatically handles message visibility based on the participants in a chatroom.

By default, participants can see messages sent at or after joining a chatroom and at or before leaving it. Former participants retain access to that history. Set `chat.can_see_messages_before_joined` to `true` to also allow earlier messages and their files; messages sent after leaving remain hidden. This setting applies to the message APIs and the participant trait's `visibleMessages()` and `getMessages()` methods.

### Getting messages for a participant
This will return all messages in the chat application. Of course, you most often need to get messages for a specific participant, which you can do by using the `getMessages` method on the participant model.

```php
$messages = ChatMessage::visibleTo($participant)->get();
```

This will return all messages that are visible to the specified participant, e.g. between the time they joined the chatroom and potentially left it. If you also want to get the messages that were sent before the participant joined the chatroom, you can pass an additional parameter:

```php
$messages = ChatMessage::visibleTo($participant, includeBeforeJoined: true)->get();
```
Passing `includeBeforeJoined: false` explicitly excludes earlier history even when the application setting is enabled. Omitting the argument, or passing `null`, inherits the application setting.
Sometimes, you may want to NOT get messages that are in chatrooms that the participant has left. You can do this by passing `false` as the second parameter:

```php
$messages = ChatMessage::visibleTo($participant, includeLeftChatrooms: false)->get();
```
This returns only the messages that are currently visible to the participant, excluding any messages from chatrooms they have left that they previously may have seen.

#### Manually building queries for visible messages
The `visibleTo` is a helper scope that you can use to build a query for messages that are visible to a participant. Behind the scenes, it builds a longer query that checks the chatroom participants and the participant's join/leave status.

```php
$messages = ChatMessage::query()
            ->afterParticipantJoined($participant)
            ->beforeParticipantDeleted($participant)
            ->get();
```

Note that you should prefer to use the `visibleTo` method as these internal methods are subject to change in future versions of Le Chat.

### Getting messages sent by a participant
If you want to retrieve only the messages sent by a specific participant, you can use the `sentBy` method:
```php
$messages = ChatMessage::sentBy($participant)->get();
```

## Marking messages as read
You can mark messages as read by calling the `markAsReadBy` method on the `ChatMessage` model:

```php
$message->markAsReadBy($participant);
```

This will mark the given message, and all previous messages in the chatroom, as read for the specified participant.

## Working with replies
To reply to a message, you can use the `replyAs` method on the `ChatMessage` model:

```php
$message->replyAs($participant, $replyContent);
```

## Mentions in your frontend

Le Chat does not provide a chat screen or a ready-made mention dropdown. Your frontend decides how people select participants and how messages are displayed. The backend provides stable mention tokens and validates them.

### 1. Get the room's participant list

Use the `participants` array returned by your chatroom API response. Each entry contains an `id`, `display_name`, and `mention_token`. See [participant resources](/participants#mentioning-a-participant) for an example.

Use participants from the **same room** as the message. Do not build the picker from a global list of users or assume that a user ID is a participant ID.

### 2. Insert the token, not the label

Suppose Alex's participant entry has `"mention_token": "<@123>"`:

- Show **@Alex** as the selectable label in your editor.
- When the person selects Alex, insert **`<@123>`** into the message value you submit.
- Your editor may display a friendly mention chip, but its saved value must retain that token.

For example, a message request for chatroom `42` contains:

```json
{
    "to_entity_type": "chatroom",
    "to_entity_id": 42,
    "message": "Hello <@123>, can you help?"
}
```

Send that payload to your configured message-send endpoint; see [Routing](/routing). Le Chat checks that participant `123` is active in room `42`. It does not resolve `@Alex` by looking for a matching display name. A mention of someone from another room returns a `422` validation response.

### 3. Render the stored token as a name

When displaying a stored message, look up each `<@123>` token in the room's participant list and display `@` followed by that participant's `display_name`. Keep the original token in your stored message and when editing it; do not save the rendered label back to the API.

If a participant cannot be resolved, keep the token as plain text rather than guessing which person it means. Treat both message text and display names as untrusted text. Do not insert them into raw HTML.

This contract works with React, Vue, a rich-text editor, or plain JavaScript. You do not have to use Le Chat's JavaScript helper.

### Optional JavaScript helper

The package includes small functions for building picker data and rendering mentions. They do **not** create a picker UI, send messages, fetch participants, or run automatically. The `.mjs` extension means the file is a JavaScript ES module, which must be imported explicitly.

For a frontend served by the **same Laravel application**, publish the helper:

```bash
php artisan vendor:publish --tag=chat-mention-assets
```

This makes `/vendor/le-chat/mentions.mjs` available from that application's public directory. Import it from a module script or your frontend's module entry point:

```javascript
import {
        mentionSuggestions,
        renderMentions,
} from '/vendor/le-chat/mentions.mjs';

// room and message are objects your frontend has already received from the API.
const options = mentionSuggestions(room.participants, 'alex');

// options contains entries such as:
// { id: 123, label: '@Alex', token: '<@123>' }
// Your picker displays option.label and inserts option.token when selected.

const messageElement = document.querySelector('#message-text');
renderMentions(messageElement, message.message, room.participants);
```

The example assumes your page has an element with `id="message-text"`. `renderMentions` replaces that element's contents with safe text nodes and `chat-mention` spans. Each span has a `data-participant-id` attribute for your own styling or click handling. Unknown or removed participant IDs remain literal text.

If your frontend is a **separate application or origin**, the import path above does not automatically point at your Laravel backend. Implement the token contract in that frontend's existing mention component, or include the helper in its own build. Publishing the helper on the backend does not wire it into a separate frontend.

Mentions can also select which AI agents respond. Configure those rules on the backend as described in [Choose when an agent responds](/ai-sdk#choose-when-an-agent-responds).

## Message blocks
Messages may contain a versioned list of section and action blocks:

```php
$message = $sender->sendMessageTo($chatroom, '', messageConfiguration: [
    'blocks' => [
        ['schema_version' => 1, 'type' => 'section', 'block_id' => 'review', 'text' => 'Review this request'],
        ['schema_version' => 1, 'type' => 'actions', 'block_id' => 'actions', 'elements' => [
            ['type' => 'button', 'action_id' => 'approve', 'text' => 'Approve'],
        ]],
    ],
]);
```

Block IDs must be unique within a message and action IDs within a block. Unsupported types and schema versions are rejected. A message may contain at most 50 blocks, each text field may contain at most 3,000 characters, and the encoded block payload may not exceed 64 KiB. When the message text is empty, the section text and button labels form its fallback. When message encryption is enabled, both the blocks and the fallback text are encrypted at rest.

Approval action blocks include a response-only `state` when the associated approval batch is loaded. It is not part of the stored block schema and must not be sent back in an edit.

Message edits use the current `version` to prevent concurrent changes from overwriting one another. The HTTP edit route requires an active participant and an application-defined `le-chat.update-message` gate; without that gate, edits are denied. A stale version returns a conflict. PHP callers must authorize the actor before calling `updateContent`.

## Sending files
Enable `Features::files()` in the [package configuration](/package-configuration#files) before using shared files.

Shared files are uploaded independently and referenced by file ID when sending messages. Only the uploader may share a file into a chatroom they can send to. Deleting a message removes its share, while other shares remain accessible. A file is downloadable only through a visible, non-deleted message share.

File visibility uses the same message scope, history setting, and inclusive join/leave boundaries. The upload time and uploader's identity do not determine who can download a shared file. Any visible share grants access; hidden or deleted shares do not. Former participants may still download files shared in messages they can see.

PHP callers can query accessible files with the same visibility overrides:

```php
use Mmedia\LeChat\Models\ChatFile;

$files = ChatFile::visibleTo($participant)->get();
$files = ChatFile::visibleTo($participant, includeBeforeJoined: true)->get();
$files = ChatFile::visibleTo($participant, includeLeftChatrooms: false)->get();
```

These overrides are server-side query options, not client-controlled download parameters. Consumer apps customizing message history must apply the same options to their file queries.

Pass ready file IDs in the message configuration when sending a message or reply:

```php
$message = $sender->sendMessageTo($chatroom, 'See attached', messageConfiguration: [
    'files' => [$file->id],
]);

$reply = $message->replyAs($sender, '', [
    'files' => [$file->id],
]);
```

An empty message is allowed when files are shared. Its text remains empty, and notifications use a generic attachment preview. The `files` option is available to `Chatroom::sendMessageAs()`; system messages may share ownerless files. Access file details through `$message->files`.

Files are stored on a private disk. The download endpoint checks the same membership period as message visibility. Bulk database deletes and database cascades bypass model events and do not update file retention; delete messages individually when cleanup is required. Message-text encryption does not encrypt file bytes.

File storage is not transactional. Configure storage limits and privacy as described in [Configuration](/package-configuration#files).
### Retrieving replies to a message
You can retrieve all 1-level deep replies to a given message using the `replies` relationship on the `ChatMessage` model:

```php
$replies = $message->replies;
```

### Retrieving the parent message
If a message is a reply to another message, you can retrieve the parent message of a reply using the `parentMessage` relationship on the `ChatMessage` model:

```php
$parentMessage = $message->parentMessage;
```

## Scoping messages to their chatrooms
So far, we have been retrieving messages across all chatrooms. If you want to scope messages to a specific chatroom, you can use the `chatroom` relationship on the `ChatMessage` model.

```php
$messages = ChatMessage::inRoom($chatroom)->get();
```

## Deleting messages
:::danger
This following actions are not reversible.
:::

Le Chat uses non-reversible overwrite on delete - the message will not be permanently removed from the database, but the content of the message will be set to `null`. This allows you to show people that a message was deleted and keep it for auditing, but remove its content. To do this, Le Chat uses the `OverwriteDeletes` trait, which is a partial override of the default `SoftDeletes` trait. You can delete messages just as you would any other soft-deleted model:

```php
$message->delete();
```

This will set the `message` attribute to `null` and set a `deleted_at` timestamp, allowing you to keep track of when the message was deleted.

If you try to restore a message that has been deleted, you will get an exception, as the content of the message cannot be restored.

Le Chat will not apply a global scope for soft deletes on the `ChatMessage` model, so you can retrieve deleted messages without any issues.

To actually delete the message from the database, you can use the `forceDelete` method:

```php
$message->forceDelete();
```

If you want to delete all message contents in all messages, you can use the `le-chat:delete-messages` Artisan command:
```bash
php artisan le-chat:delete-messages
```

## Working with the message model
The `ChatMessage` model has several useful methods that you can use to work with messages.

### Getting the sender of a message
You can get the sender of a message using the `sender` relationship:
```php
$sender = $message->sender; // Returns the participant who sent the message
```
This returns the `ChatParticipant` morph model, which represents the participant who sent the message. This is by design, letting you work with a unified interface for all participants, regardless of their underlying model.

Remember that not all messages have a sender. For example, system messages do not have a sender, so the `sender` relationship may return `null` in those cases.

### Returning the actual model that sent the message
This will return an instance of the `ChatParticipant` model, which you can then use to get the actual model that sent the message (e.g., a User model).
```php
$senderModel = $message->sender->participatingModel; // Returns the actual model that sent the message
```

## Encrypting messages at rest
Le Chat supports encrypting messages at rest. This means that messages are stored in an encrypted format in the database, ensuring that they are secure even if the database is compromised.

Enable this feature in the [package configuration](/package-configuration) by adding the `encryptMessagesAtRest` feature.

### Migrating existing messages to encrypted format and back
If you have existing messages in the database that you want to encrypt, you can use the `le-chat:encrypt-messages` command to migrate them to the encrypted format:
```bash
php artisan le-chat:encrypt-messages
```
This command will go through all messages in the database and encrypt them.

If you need to reverse this process and decrypt the messages, you can use the `le-chat:decrypt-messages` command:
```bash
php artisan le-chat:decrypt-messages
```

This command will go through all messages in the database and decrypt them.