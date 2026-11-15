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