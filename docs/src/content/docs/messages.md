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

By default, participants can see messages sent at or after joining a chatroom and at or before leaving it. Former participants retain access to that history. Set `chat.can_see_messages_before_joined` to `true` to enable earlier history by default for newly created rooms. Each room copies this default to its newly created participant memberships. Messages sent after leaving remain hidden.

### History access per room and participant

Each participant membership stores whether it can read messages sent before joining. The global configuration supplies the default for new rooms; each room supplies the default for new memberships. These values are copied at creation, not inherited when reading messages.

#### Set the default for new participants

Call `setCanSeeMessagesBeforeJoined(true)` on a chatroom to allow earlier messages for memberships created after this change:

```php
$chatroom->setCanSeeMessagesBeforeJoined(true);
```

Existing memberships do not change. Changing the global configuration likewise does not change existing rooms. Use `false` to disable history for future memberships in this room.

#### Allow earlier messages for one participant

`addParticipant()` returns a `ChatParticipant`: the membership record connecting a chat-capable model to one room. Set history access on that record, not on the underlying model:

```php
$membership = $chatroom->addParticipant($participant);
$membership->setCanSeeMessagesBeforeJoined(true);
```

The participant can now read earlier messages in this room. Their access in other rooms does not change, and other participants in this room keep their own settings.

Use `false` to hide earlier messages for a participant, even when the room allows them:

```php
$membership->setCanSeeMessagesBeforeJoined(false);
```

To change an existing membership, retrieve it from the room first. `participant()` returns `null` if the model is not a member:

```php
$membership = $chatroom->participant($participant);

if ($membership !== null) {
    $membership->setCanSeeMessagesBeforeJoined(true);
}
```

#### Stored settings

| Value | Meaning |
| --- | --- |
| `true` | Show messages sent before joining. |
| `false` | Hide messages sent before joining. |

Both setters accept `bool`, save the change immediately, and return `void`; you do not need to call `save()`. Rooms and memberships each have a non-nullable boolean `can_see_messages_before_joined` database column. `null` is not a stored setting.

Both setters intentionally use normal Eloquent updates: changing the value updates `updated_at` and fires model events. Observers are not suppressed, so auditing and cache invalidation can react to history-setting changes.

You can also supply the boolean when creating a room or membership directly. An explicitly supplied value is preserved instead of copying the creation default.

Defaults are copied by Eloquent model creation events. Raw database inserts bypass those events and use the database default of `false` unless you supply a value.

For bulk membership creation, use the room relation so its default is available without a lookup for each membership:

```php
$chatroom->participants()->createMany([
    ['reference_id' => 'first', 'display_name' => 'First participant'],
    ['reference_id' => 'second', 'display_name' => 'Second participant'],
]);
```

An explicitly supplied membership setting takes priority. Direct `ChatParticipant::create()` calls that supply only a room ID look up that room's current default; bulk callers using this path can supply `can_see_messages_before_joined` explicitly to avoid those lookups. Defaults are not cached across creations, so later room changes do not leave a stale cached value.

When upgrading an existing installation, publish and run the new migration before using this version:

```bash
php artisan vendor:publish --tag="le-chat-migrations"
php artisan migrate
```

The migration initializes existing rooms and memberships, including soft-deleted records, from `chat.can_see_messages_before_joined` as configured when the migration runs. Keep that configuration at its existing value during the upgrade to preserve history access. Existing join/leave timestamps and read markers are unchanged.

#### Reading messages

Message visibility uses only the requesting membership's stored setting. It does not look up a room default or global configuration to decide history access.

For example, a room starts with history disabled. Its first membership copies `false`. You then enable history on the room. A second membership copies `true`, while the first remains `false`. Change the first membership explicitly if it should also see earlier messages.

These rules apply separately to each membership when a query includes several rooms. You can pass either a chat-capable model, such as a user, or a `ChatParticipant` record to `visibleTo()`.

#### Membership lifecycle

Adding or synchronizing the same participant again preserves their history setting. Removing and restoring their membership also preserves it. Do not change their join timestamp to grant history access.

#### What history access changes

History settings apply to participant-visible message lists, individual messages, parent and reply content, shared files, latest-message previews, unread counts, and participant-scoped AI history. Raw queries such as `ChatMessage::get()` and `$chatroom->messages()` are not filtered for a viewer; use `visibleTo()` when serving messages to a participant.

Changes to a membership's setting take effect on subsequent reads, including reads of existing messages. They do not mark messages as read, reset `read_at`, or retract content someone has already downloaded.

History access does not add members, change roles, grant posting or editing permissions, authorize AI tool approvals, or expose private AI metadata. Deleted messages and file shares keep their existing visibility rules.

Participants still cannot see messages sent after they left, even with history enabled. Messages exactly at the join or leave timestamp are included. `includeLeftChatrooms: false` excludes rooms the participant has left entirely. Restoring a membership uses its original join time, so messages sent during the absence become visible again under the existing restoration behavior.

#### Keep settings under server control

Your application must authorize changes to room and membership settings. The setters do not perform application-specific authorization, add or restore memberships, or change roles. There are no dedicated HTTP endpoints for changing these settings.

An explicit `includeBeforeJoined: true` or `false` overrides even a membership's setting. Only use this argument in trusted server code; do not copy it from a request parameter. Use the same override for message and file queries so attachments follow the history shown to the participant.

### Getting messages for a participant
This will return all messages in the chat application. Of course, you most often need to get messages for a specific participant, which you can do by using the `getMessages` method on the participant model.

```php
$messages = ChatMessage::visibleTo($participant)->get();
```

This will return all messages that are visible to the specified participant, e.g. between the time they joined the chatroom and potentially left it. If you also want to get the messages that were sent before the participant joined the chatroom, you can pass an additional parameter:

```php
$messages = ChatMessage::visibleTo($participant, includeBeforeJoined: true)->get();
```
Passing `includeBeforeJoined: false` explicitly excludes earlier history even when the membership enables it. Omitting the argument, or passing `null`, uses the membership's stored setting. These query overrides do not change any stored setting.
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