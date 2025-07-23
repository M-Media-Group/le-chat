---
title: Participants
description: Documentation for managing chat participants in Le Chat.
---

## Introduction
Le Chat is designed to allow any model to be a chat participant. This means you can have conversations with users, models, or any combination of models that implement the necessary interfaces and traits.

For the purposes of this documentation, we will use the example of a `Teacher` and a `Student` model as the [configured models](#configuring-a-model-for-chatting).

## Configuring a Model for Chatting
See the [Configuring Models](/configuring-models) documentation for details on how to prepare your models to send and receive messages.

## Sending a Message
Once your models are configured, you can send messages between them. Here's an example of how to send a message from one participant to another:

```php
$teacher->sendMessageTo($student, 'Hello, how are you?');
```
Behind the scenes, Le Chat will automatically create a chatroom, add you and the recipient to it, and send the message to the chatroom.

You can also pass a chatroom directly if you want to send a message to a specific chatroom:

```php
$teacher->sendMessageTo($chatroom, 'Hi class!');
```

### The message lifecycle
When you send a message, Le Chat will automatically create a chatroom if one doesn't already exist between only the participants and the sender.

If you send a message to a single participant, Le Chat will create a chatroom with just those two participants (the sender and the recipient). If you add another participant to the chatroom, the next time you call `sendMessageTo` with only the original participant and NOT an array of all the participants, Le Chat will create a new private chatroom with just the sender and the participant.

```php
$firstMessage = $teacher->sendMessageTo($student, 'Hello!');

$firstMessage->chatroom->addParticipant($thirdParticipant);

$teacher->sendMessageTo($student, 'Hello again!'); // This will create a new chatroom with just the teacher and the student

$teacher->sendMessageTo([$student, $thirdParticipant], 'Hi all!'); // This will re-use the existing original chatroom with all three participants
$teacher->sendMessageTo($firstMessage->chatroom, 'Hi all!'); // This will use the chatroom directly
```

## Seeing messages
Different participants will see different messages in the same chatrooms depending on when they joined and left the chatroom.
```php
$messages = $teacher->getMessages(); // Will return all messages visible to the teacher in all chatrooms
```
### Message visibility
When building multi-user chat applications, it is common not to show messages to new participants that were exchanged in a chatroom before they joined.

By default, only messages from between the time a participant joins the chatroom and potentially leaves it are visible to that participant. This means that if a participant joins a chatroom after messages have been sent, they will not see those previous messages.

If you want to include messages that were sent before the participant joined the chatroom, you can use the `getMessages` method with an additional parameter:

```php
$messages = $teacher->getMessages(includeBeforeJoined: true); // Will return all messages including those sent before the teacher joined the chatroom
```
### Paginating messages
You can paginate the messages for a participant using the `getMessages` method with optional first two parameters for limit and offset:
```php
$messages = $teacher->getMessages(limit: 10, offset: 0); // Will return the first 10 messages visible to the teacher
```

### Building in a query
You can also build a query to retrieve messages for a participant using the `messages` relationship:
```php
$messages = $teacher->visibleMessages()->get(); // Will return all messages visible to the teacher in all chatrooms

$messages = $teacher->visibleMessages(includeBeforeJoined: true)->get(); // Will return all messages visible to the teacher in all chatrooms
```

### Retrieving sent messages
If you want to retrieve only the messages sent by a participant, you can use the `sentMessages` method:
```php
$sentMessages = $teacher->sentMessages()->get(); // Will return all messages sent by the teacher
```

## Reading messages
You can mark messages as read by calling the `read` method on the `ChatMessage` model:
```php
$student->markRead($message); // will mark the given message and any previous messages in the chatroom up until the message as read for the student
```

If you want to mark all messages in a chatroom as read for a participant, you can use the `markRead` method on the participant model and pass the chatroom instead of a message:
```php
$student->markRead($chatroom); // will mark all messages in the chatroom as read for the student
```

Alternatively, you can use the `markReadUntil` method to mark all messages in a chatroom as read up until a specific time:
```php
$student->markReadUntil($chatroom, now()); // will mark all messages in the chatroom as read for the student, up until the current time
```

### Retrieving users with unread messages
You may want to retrieve all participants in a chatroom that have unread messages, for example, to send them a daily notification about messages they've missed.

You can retrieve all participants in a chatroom that have unread messages by using the `whereHasUnreadMessagesToday` scope on your models:
```php
User::whereHasUnreadMessagesToday()->get(); // Will return all users that have unread messages in the chatroom today
```

This will return all participants in the chatroom that have any unread messages today in any chatroom, excluding system messages. If you want to include system messages, you can use the `whereHasUnreadMessagesToday(includeSystemMessages: true)` scope:
```php
User::whereHasUnreadMessagesToday(includeSystemMessages: true)->get(); // Will return all
```

If you want to use a different date range, you can use the `whereHasUnreadMessages` scope and pass a date range:
```php
$users = User::whereHasUnreadMessages(7, true)->get(); // unread messages sent in the last 7 days, including system messages
```

Le Chat will not consider deleted messages as unread, so if a message was deleted, it will not be counted as unread.

## Getting a personal chatroom
You can retrieve a "personal chatroom" for a participant, which is a chatroom that only contains that participant. This is useful for sending notifications or system messages that are not part of any other chatroom.

```php
$personalChatroom = $teacher->getOrCreatePersonalChatroom(); // Will return the first chatroom that only contains the teacher as a participant
```

If you want to configure the personal chatroom if its created, you can pass an array of attributes to the `getOrCreatePersonalChatroom` method:

```php
$teacher->getOrCreatePersonalChatroom([
    'name' => 'My Personal Chatroom',
    'description' => 'This is my personal chatroom for notifications and system messages, and sometimes notes.',
    'metadata' => [
        'foo' => 'bar', // Optional metadata for the personal chatroom
    ],
]);
```

## Working with the intermediate morph model
Le Chat uses an intermediate morph model called `ChatParticipant` to manage the relationships between your chattable models and the chatrooms they participate in.

This model represents a participant in a chatroom and contains additional information such as the participant's join and leave timestamps, as well as their display name and avatar.

The intermediate model is more than just a pivot table; it allows you to have a unified representation of all participants in a chatroom, separate from your own models. This allows you to maximise the separation of concerns between your application logic and the chat functionality.

### Getting the intermediate model from your model
You can retrieve the `ChatParticipant` instance for a specific participant using the `asParticipantIn` method on your model:
```php
$chatParticipant = $teacher->asParticipantIn($chatroom);
```
This will return the `ChatParticipant` instance that links your model to the specified chatroom, allowing you to access additional information about their participation.

In order to do the inverse and get the model from the `ChatParticipant`, you can use the `participatingModel` relationship:
```php
$participant = $chatParticipant->participatingModel; // Will return the original model (e.g., Teacher or Student)
```

### Customizing the display name and avatar
By default, Le Chat will use the `name` or `email` attribute of your model as the display name and the `avatar_url` attribute as the avatar URL. However, you can customize this behavior by implementing the `getDisplayName` and `getAvatarUrl` methods in your model.

```diff lang="php"
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Traits\IsChatParticipant;

class Teacher implements ChatParticipantInterface
{
    use IsChatParticipant;

+    public function getDisplayName(): string
+    {
+        return $this->name;
+    }

+    public function getAvatarUrl(): string
+    {
+        return $this->avatar_url;
+    }
}
```

You can also override and persist these values in the `ChatParticipant` model itself, allowing you to use different names and avatars for the same model in different chatrooms.

```php
$chatParticipant = $teacher->asParticipantIn($chatroom);

$chatParticipant->update([
    'display_name' => 'Jack (Geography Substitute)',
    'avatar_url' => 'https://example.com/custom-avatar.png',
]);
```

### Making requests using the `ChatParticipant` model
On the majority of methods where you can pass your own models, you can also pass a `ChatParticipant` model. This allows you to work with the participant in a chatroom without needing to know the underlying model, or even work with it if it has no relation to your application logic.
```php
$chatrooms = Chatroom::hasParticipant([$teacher, $chatParticipant]); // Will return all chatrooms where the participant is currently active
```

The model itself also implements the `ChatParticipantInterface`, and uses the trait, so you can use it in the same way as your own models:
```php
$chatParticipant->sendMessageTo($student, 'Hello from the chat participant!');
```