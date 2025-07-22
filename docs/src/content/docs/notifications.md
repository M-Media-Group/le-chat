---
title: Notifications
description: Documentation for Le Chat notifications.
---

## Introduction
Le Chat provides a powerful notification system that allows you to send notifications to chat participants when certain events occur, such as when a new message is sent or when a participant is added to a chatroom.

## Checking a Participant's Notifiability
Because a participant can be linked to any model or no model at all, it may or may not be notifiable. To determine this you'd need to check if the model uses the Laravel `Notifiable` trait.

Le Chat provides a convenient method to check if a `ChatParticipant` is linked to a model that is notifiable:
```php
if ($chatParticipant->is_notifiable) {
    // The linked model is notifiable, so you can send notifications to it with $chatParticipant->participatingModel->notify();
}

// Or on your own model:
if ($teacher->is_notifiable) {
    // The teacher model is notifiable, so you can send notifications to it.
}
```

## Retrieving all notifiable participants in a chatroom

You may want to get all the notifiable participants in a chatroom, for example, to send them an individual notification about an event. You can do this by calling the `getNotifiableParticipants` method on the `Chatroom` model. This will return a collection of `ChatParticipant` models that are linked to a model that uses the `Notifiable` trait.

```php
$notifiableParticipants = $chatroom->getNotifiableParticipants(); // will return a collection of ChatParticipant models that are morphable to a model that uses the Notifiable trait.

// Now you can send notifications to the notifiable participants
foreach ($notifiableParticipants as $participant) {
    $actualParticipant = $participant->participatingModel;
    $actualParticipant->notify(new MyNotification());
}
```

## Default New Message Notification
The default notification is `\Mmedia\LeChat\Notifications\NewMessage::class`, which will send a notification to each of the participants personal channels when a new message is created, except the sender of the message, via the [Broadcasting](/broadcasting) channel.

If you have the [`WebPush`](https://laravel-notification-channels.com/webpush/#web-push-notifications-channel-for-laravel) channel installed, the notification will automatically be sent via web-push if the participant is not connected to the chatroom via sockets. This is useful for sending notifications to users who are not currently online in the chatroom.

### Difference from the event broadcast
The `NewMessage` notification is sent to the personal channel of each participant, while the event is broadcasted to the chatroom presence channel. This means that the notification will be sent to each participant individually, while the event is sent to the chatroom as a whole.

This is useful to send notifications to participants who are currently online, but not connected to any chatroom via sockets, or to send notifications to participants who are not currently online at all via web-push, for example.

## Using Chatrooms as notification channels
It can be helpful to use chatroom messages as a notification channel. Internally, LeChat does this when new participants are added or removed from a chatroom to create system-messages about these events.

You can use the `ChatroomChannel` notification channel to send notifications to a chatroom. This channel will automatically create a new message in the chatroom with the notification data.

```php
use Mmedia\LeChat\Notifications\ChatroomChannel;
use Mmedia\LeChat\Notifications\ChatroomChannelMessage;

    public function via(object $notifiable): array
    {
        // Now you can send notifications as chatroom messages
        return [ChatroomChannel::class];
    }

    // Implement the `toChatroom` method in your notification class
    public function toChatroom(object $notifiable): ChatroomChannelMessage
    {
        return (new ChatroomChannelMessage)
            ->message("My custom message");
    }
```
By default, the notification will not be attached/"sent" by any particular participant. It will be sent to the personal chatroom of the notifiable - e.g. the first chatroom that only contains them as a participant.

If you want to send the notification to a specific chatroom, you can use the `chatroom` method on the `ChatroomChannelMessage` class:

```php
public function toChatroom(object $notifiable): ChatroomChannelMessage
{
    return (new ChatroomChannelMessage)
        ->message("Severe weather alert start")
        ->attributes(['created_at' => now()->addDays(1)]) // Additional attributes to be force-filled when creating the message
        ->chatroom($chatroom); // The ID of the chatroom to send the notification
}
```

If you want to send the notification as a specific participant in the chatroom, you can use the `sender` method on the `ChatroomChannelMessage` class:

```php
public function toChatroom(object $notifiable): ChatroomChannelMessage
{
    return (new ChatroomChannelMessage)
        ->message("I just got my first A+!")
        // IMPORTANT: specify the chatroom first if your sender is not a ChatParticipant, but one of your own models
        ->chatroom($chatroom) // The ID of the chatroom to send the notification
        ->sender($notifiable); // The participant to send the notification as
}
```
