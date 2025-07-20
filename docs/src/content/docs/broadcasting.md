---
title: Broadcasting
description: Documentation for Laravel Chat broadcasting.
---

## Introduction
Laravel Chat comes with out-of-the-box support for broadcasting messages in real-time. This allows you to create a more interactive chat experience by pushing new messages to users as they are sent.

## Broadcasting Setup
To enable broadcasting, you need to configure broadcasting in your Laravel application. Refer to the [Laravel Broadcasting documentation](https://laravel.com/docs/broadcasting) for detailed instructions on setting up broadcasting.

### Authentication
Make sure you have the necessary authentication set up for broadcasting. You should at least create the following routes in your `routes/channels.php` file:

```php
use Illuminate\Support\Facades\Broadcast;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Http\Resources\ChatParticipantResource;
use Mmedia\LaravelChat\Models\ChatParticipant;
use Mmedia\LaravelChat\Models\Chatroom;

Broadcast::channel((new Chatroom)->broadcastChannelRoute(), function (ChatParticipantInterface|ChatParticipant $user, Chatroom $chatroom) {
    return $chatroom->hasParticipant($user) ?
        // Note the usage of a standardized resource here to ensure consistent data structure
        ChatParticipantResource::make($chatroom->participant($user))->resolve() :
        false;
});
```

## Broadcasting Channels
Laravel Chat uses presence channels for each chatroom to broadcast messages on. This also allows you to track which users are currently online in a chatroom, as well as use things like typing indicators via sockets.

The broadcasting channel used will be the `broadcastChannel()` of the chatroom, e.g. `Mmedia.LaravelChat.Models.Chatroom.<chatroom_id>`.

Additionally, if you are using [Notifications](/notifications), the `MessageCreated` notification will also be broadcasted to each individual participant of the chatroom. Refer to the [Notifications documentation](/notifications) for more details this.

Laravel Chat will not broadcast to the current user as they already have the message.

## Broadcasted Events
Refer to the [Events documentation](/events) for a list of events that are broadcasted by Laravel Chat. The event name will be the fully qualified class name of the event, e.g. `.Mmedia\\LaravelChat\\Events\\MessageCreated`. Note the starting dot that is used in Echos side.

### Broadcasted data
All broadcasted events include a standardized data structure based on [Resources](/resources).

## Client side
To listen to the broadcasted events, you can use Laravel Echo or any other WebSocket client that supports presence channels. Here is an example using Laravel Echo:


```ts
import Echo from 'laravel-echo';

const channelName = `Mmedia.LaravelChat.Models.Chatroom.${chatId}`;
const newMessageEvent = '.Mmedia\\LaravelChat\\Events\\MessageCreated';

const channel = Echo.join(channelName);

channel
.here((allUsers: Chatroom['participants']) => {
    console.log("Participants currently in the chatroom:", allUsers);
})
.joining((newUser: Chatroom['participants'][number]) => {
    console.log("New participant joined:", newUser);
})
.leaving((leavingUser: Chatroom['participants'][number]) => {
    console.log("Participant left the chatroom:", leavingUser);
})
.listen(eventName, (e: Chatroom['messages'][number] | Chatroom['participants'][number]) => {
    if (eventName === newMessageEvent) {
        console.log("New message received:", e);
        return;
    }
    console.log("Participant added/removed event received:", e);
})
.error((error: unknown) => {
    console.error(`Error joining chatroom ${chatId}:`, error);
});
```

## Determining if a participant is connected to a chatroom via WebSockets
:::note
Only the `pusher` and `reverb` broadcast drivers are supported for this feature.
:::

Sometimes, it is useful to know if a participant is currently connected to the chatroom via sockets. You can use this for example to conditionally send web-push notifications to users if they are not currently online in the chatroom.

You can check if a participant is currently connected to the chatroom via sockets by using the `is_connected` property on the `ChatParticipant` model.
```php
$participant = ChatParticipant::find($participantId);
if ($participant->is_connected) {
    // The participant is currently connected to the chatroom presence channel via sockets
} else {
    // The participant is not connected to the chatroom presence channel via sockets
}
```

Internally, we call the [`get users`](https://pusher.com/docs/chatrooms/library_auth_reference/rest-api/#get-users) endpoint of the chatroom to check if the participant is currently connected to the chatroom.

### Determining if your model is connected to a chatroom
You can also check if your own model is connected to a chatroom via sockets by using the `isConnectedToChatroomViaSockets` method on your model. This method will return `true` if the model is currently connected to the chatroom presence channel via sockets, and `false` otherwise.
```php
$teacher->isConnectedToChatroomViaSockets($chatroom);
```

### Extending socket-presence checks to other models
You can use this feature on other models to check their connection status to different channels, not just chatrooms.

First, ensure that your model uses the `ConnectsToBroadcast` trait:
```php
use Mmedia\LaravelChat\Traits\ConnectsToBroadcast;

class YourModel extends Model
{
    use ConnectsToBroadcast;

    // Your model code here
}
```

#### Checking connection status
Then, you can check if the model is connected to a specific channel:
```php
$yourModel = YourModel::find($id);

$yourModel->is_connected; // Returns true if connected to own private channel
// OR
$yourModel->getIsConnectedViaSockets(); // True if connected, otherwise false. If the connection could not be determined, it will return null.
```

The default channel is the private channel for the model as defined by the native Laravel `broadcastChannel()` method.

#### Checking connection status for specific channels
If you want to check for a specific channel, you can pass the channel name as an argument:

```php
if ($yourModel->getIsConnectedViaSockets('your.channel.name', 'private')) {
    // The model is connected to the specified private channel
} else {
    // The model is not connected to the specified channel
}
```