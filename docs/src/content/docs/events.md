---
title: Events
description: Documentation for Le Chat events.
---

## Introduction
Le Chat provides a set of events that you can listen to in order to react to changes in the chat system. These events allow you to perform actions when participants are added or removed from chatrooms, or when messages are sent.

## Available Events
All available events are located in the `Mmedia\LeChat\Events` namespace. If you have [broadcasting](/broadcasting) enabled, these events will also be broadcasted to the frontend.
### Message Created
This event is fired when a message is sent in a chatroom. You can listen to this event to perform actions such as logging, notifications, or any other custom logic.
```php
use Mmedia\LeChat\Events\MessageCreated;
```

The event contains the following properties:
- `message`: The `ChatMessage` model that was created.

### Message Updated
This event is fired when a message is updated in a chatroom. You can listen to this event to perform actions such as logging changes or updating the UI.

```php
use Mmedia\LeChat\Events\MessageUpdated;
```

The event contains the following properties:
- `message`: The `ChatMessage` model that was updated.

### Participant Created
This event is fired when a participant is added to a chatroom. You can use this event to perform actions such as notifying the participant or updating the UI.
```php
use Mmedia\LeChat\Events\ParticipantCreated;
```

The event contains the following properties:
- `participant`: The `ChatParticipant` model that was created. To get your own linked model, you can use the `participatingModel` property, like `$event->participant->participatingModel`.

### Participant Deleted
This event is fired when a participant is removed from a chatroom. You can listen to this event to perform actions such as cleaning up resources or notifying other participants.
```php
use Mmedia\LeChat\Events\ParticipantDeleted;
```

The event contains the following properties:
- `participant`: The `ChatParticipant` model that was deleted. To get your own linked model, you can use the `participatingModel` property, like `$event->participant->participatingModel`.

### Participant Typing
Normally, typing indicators are sent client to client using a whisper. However, if a server-side participant needs to indicate typing status, the `ParticipantTyping` event can be used.

For example, Le Chat uses this internally if you use the AI SDK to indicate when the AI is "typing" a response.

```php
use Mmedia\LeChat\Events\ParticipantTyping;
```

The event contains the following properties:
- `participant`: The `ChatParticipant` model that is typing. To get your own linked model, you can use the `participatingModel` property, like `$event->participant->participatingModel`.
- `is_typing`: A boolean indicating whether the participant is currently typing.