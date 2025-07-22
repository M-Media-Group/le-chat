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

Messages are visible to participants who are part of the chatroom. Only messages from between the time a participant joins the chatroom and potentially leaves it are visible to them. This means that if a participant joins a chatroom after messages have been sent, they will not see those previous messages.

### Getting messages for a participant
This will return all messages in the chat application. Of course, you most often need to get messages for a specific participant, which you can do by using the `getMessages` method on the participant model.

```php
$messages = ChatMessage::visibleTo($participant)->get();
```

This will return all messages that are visible to the specified participant, e.g. between the time they joined the chatroom and potentially left it. If you also want to get the messages that were sent before the participant joined the chatroom, you can pass an additional parameter:

```php
$messages = ChatMessage::visibleTo($participant, includeBeforeJoined: true)->get();
```
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

## Scoping messages to their chatrooms
So far, we have been retrieving messages across all chatrooms. If you want to scope messages to a specific chatroom, you can use the `chatroom` relationship on the `ChatMessage` model.

```php
$messages = ChatMessage::inRoom($chatroom)->get();
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

### Allowing failed decryption
When your application is switching from unencrypted to encrypted messages but already has messages in the database, and for some reason you are not yet able to run the Artisan commands, you can allow failed decryption by setting the `return_failed_decrypt` option to `true` in the feature configuration:
```php
// config/chat.php
'features' => [
    Features::encryptMessagesAtRest([
        'return_failed_decrypt' => true,
    ]),
],
```
This will allow your application to return the original message content if decryption fails, instead of throwing an exception. This is useful for transitioning existing messages to encrypted storage without losing data.

You should NOT rely on this feature as it may be unsafe. Instead, when switching to encrypted messages, you should migrate your existing messages to the encrypted format with the provided commands.