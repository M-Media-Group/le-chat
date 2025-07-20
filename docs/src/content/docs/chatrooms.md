---
title: Chatrooms
description: Documentation for managing chatrooms in Le Chat.
---

## Introduction
Le Chat allows you to create and manage chatrooms, enabling group conversations among multiple participants. This feature is essential for applications that require collaborative communication.

You don't actually need to use chatrooms to send messages, as Le Chat [automatically resolves chatrooms](/messages) when sending messages.

## Creating a Chatroom

To create a chatroom, you can use the `Chatroom` model. Here's an example of how to create a new chatroom:

```php
use Mmedia\LeChat\Models\Chatroom;

$chatroom = Chatroom::create([
    'name' => 'General Chat',
    'description' => 'A chatroom for general discussions',
]);
```

## Adding a Participant

You can add any model that is a [participant](/configuring-models) to a chatroom. To add participants.

```php
$chatroom->addParticipant($student);
```

### Adding Multiple Participants
You can also add multiple participants at once by passing an array of models:
```php
$chatroom->addParticipants([$teacher, $student]);
```

### Adding Participants with a Role
You can also specify a role for the participant when adding them to the chatroom. The possible roles are `admin` or `member`.

```php
$chatroom->addParticipant($teacher, 'admin');

$chatroom->addParticipant([$studentA, $studentB], 'member'); // Add multiple participants with the same role
```

## Removing a Participant
To remove a participant from a chatroom, you can use the `removeParticipant` method:
```php
$chatroom->removeParticipant($student);
```

### Removing Multiple Participants
You can also remove multiple participants at once:
```php
$chatroom->removeParticipants([$teacher, $student]);
```

### Synchronizing Participants
If you want to synchronize the participants in a chatroom, you can use the `syncParticipants` method. This will remove all participants not in the provided array and add any new participants that are not already in the chatroom.
```php
$chatroom->syncParticipants([$teacher, $student]);
```

If you want to synchronize participants with a specific role, you can pass the role as the second argument:
```php
$chatroom->syncParticipants([$teacher, $student], 'admin');
```

## Retrieve a Chatroom by Participants

### Exactly Matching Participants
You can retrieve a chatroom by its participants using the `Chatroom` model's `whereParticipants` method. This is useful when you want to find an existing chatroom for a specific set of participants.

```php
use Mmedia\LeChat\Models\Chatroom;

$chatroom = Chatroom::havingExactlyParticipants([$teacher, $student])->first();
```

This will retrieve the first chatroom that has exactly the specified participants currently in it.

If you also want to include chatrooms where some of the participants may have since left, you can pass `true` as the second argument:

```php
$chatroom = Chatroom::havingExactlyParticipants([$teacher, $student], true)->first();
```

### At Least Matching Participants
If you want to find a chatroom that has at least the specified participants, you can use the `havingParticipants` method. For example, you may want to get all the chatrooms that a participant is part of, regardless of whether there are additional participants in the chatroom:
```php
$chatrooms = Chatroom::havingParticipants([$teacher])->get();
```

This will retrieve all chatrooms that have at least the specified participants, regardless of whether there are additional participants in the chatroom.

Likewise, if you want to include chatrooms where some of the participants may have since left, you can pass `true` as the second argument:
```php
$chatrooms = Chatroom::havingParticipants([$teacher], true)->get();
```

## Checking if Participants are in a Chatroom
You can check if a participant is active in a chatroom using the `hasParticipant` method:
```php
$chatrooms = Chatroom::hasParticipant($teacher); // bool
```

This will return `true` if the specified participant is currently in the chatroom, and `false` otherwise. If you want to check if a participant was ever in the chatroom, even if they have since left, you can use the `hasOrHadParticipant` method:
```php
$chatrooms = Chatroom::hasOrHadParticipant($teacher); // bool
```

## Getting the Participant in a Chatroom
You can retrieve a specific participant in a chatroom using the `participant` method:
```php
$participant = $chatroom->participant($teacher);
```

This returns the `ChatParticipant` model for the specified participant in the chatroom. If the participant is not in the chatroom, it will return `null`. Refer to the [Chat Participant documentation](/participants) for more details on the `ChatParticipant` model.

## Sending Messages as a System User
If you want to send messages in a chatroom as a system user (e.g., for notifications or automated messages), you can use the `sendMessage` method on the chatroom instance.
```php
$chatroom->sendMessage('Martha joined the chat');
```

This creates a new message that is not associated with any sender, effectively treating it as a system message. The message will be stored in the database and can be retrieved like any other message, but it will not have any model associated with it.

Sometimes you may need to add additional arguments to the `sendMessage` method, such as a specific type or additional metadata. You can do this by passing an array of options:
```php
$chatroom->sendMessage('Severe weather alert start', [
    'created_at' => now()->addDays(1)
]);
```

## Sending Messages as a Participant
If you want to send a message as a specific participant, you can use the `sendMessageAs` method. This allows you to specify which participant is sending the message.
```php
$chatroom->sendMessageAs($teacher, 'Hello students'); // will send the message as the teacher
```

If you need to, you can also pass additional attributes to the message:
```php
$chatroom->sendMessageAs($teacher, 'Hello students', [
    'created_at' => now()->addDays(1) // Force the created_at attribute
]);
```

## Getting messages in a Chatroom
You can retrieve messages from a chatroom using the `messages` relationship. This will return all messages associated with the chatroom.
```php
$messages = $chatroom->messages()->get();
```

This is a regular model that references the [Message](/messages) model, so you can use all the methods available on the `Message` model to filter, sort, and manipulate the messages as needed, e.g.:
```php
$messages = $chatroom->messages()->visibleTo($teacher)->get(); // Returns messages that can be read by the teacher
```