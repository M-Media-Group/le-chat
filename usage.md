# Usage
Laravel Chat allows you to add chat functionality to your Laravel application. It provides a simple and flexible API for sending and receiving messages between models.

```php
$message = $user->sendMessageTo($otherUser, "Hello");

// Super easy reply - LaravelChat automatically finds the latest chatroom between the two users and sends the message to it
$reply = $otherUser->sendMessageTo($user, "Hi there");

// Get the messages on the other side
$messages = $otherUser->getMessages();

// Get the sent messages
$messages = $user->getSentMessages();

// Get the sent messages to a specific user (across all chatrooms)
$messages = $user->getMessagesSentTo($otherUser);

// Get the sent messages to a specific chatroom
$messages = $user->getMessagesSentTo($message->chatroom);
```

## Difference from Chatify
I made this package because I disagreed with a fundamental design decision in Chatify, which was that a message can only have one sender and one recipient.

This package allows you to send messages to multiple recipients, and any models. You can also create multi-user chat rooms, and send messages to chatrooms.

## Other projects
- https://github.com/namumakwembo/wirechat - nice package but livewire heavy
- https://github.com/musonza/chat - nice but some code looks overly complex
-

## Requirements
- PHP 8.0 or higher
- Laravel 11 or higher

## Installation
You can install the package via composer:

```bash
composer require mmedia/laravel-chat
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-chat-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-chat-config"
```

There are no views.

## Key concepts
- There is a `Chatroom` model that represents a chat chatroom. A chatroom can have many `ChatParticipant`s and many messages.
- There is a `ChatParticipant` model that represents a participant in a chatroom. A `ChatParticipant` can reference via morphing pretty much anything.
- There is a `Message` model that represents a message in a chatroom. A message belongs to the sender `ChatParticipant`, and belongs to a `Chatroom`.
- Any model can use the `IsChatParticipant` trait to make it possible for that entity to send and receive messages. Additionally, the model must implement the `ChatParticipantInterface`.

## Sample use cases
- Many to many polymorphic model chat (e.g. teachers and students chat in a specific class chatroom)
- One to one polymorphic model chat (e.g. teacher and student chat in a private chat)
- One to one abstract chat (e.g. student (an actual model) and AI (a non-existent model) chat)
- Chatroom connected with external services (teachers and students chat, and Slack messages are forwarded/sent to the chatroom via a bot)

## High level
Note, this high level overview here does not cover permissions. Your app should handle permissions and access to chatrooms at a layer above this package.

## Docs

### Sending messages
First, prepare the models to send and receive messages. The models must use the `IsChatParticipant` trait. Also implement the `ChatParticipantInterface` interface in your models, which is a contract for all chat participants.

```php
class Teacher extends Model implements ChatParticipantInterface
{
    use IsChatParticipant;

    // Your model code here
}

class Student extends Model implements ChatParticipantInterface
{
    use IsChatParticipant;

    // Your model code here
}
```

#### Send a message to a model
```php

// will re-use the latest chatroom where only the teacher and student are in, or if not found, will create a new chatroom with both participants. The new chatroom will be created with the teacher as the owner and the student as a participant
$messageId = $teacher->sendMessageTo($student, $message);

$messageId = $teacher->sendMessageTo($student, $message, true); // will create a new chatroom even if one exists

$messageId = $teacher->sendMessageTo($student, $message, true, [
    'name' => 'My custom chatroom name',
    'description' => 'My custom chatroom description',
    'metadata' => [
        'foo' => 'bar',
    ],
]); // will create a new chatroom with the given name, description and metadata

$messageId = $teacher->sendMessageTo([$studentA, $studentB], $message); // will re-use the latest chatroom where only the teacher and both students are in, or if not found, will create a new chatroom with both participants. The new chatroom will be created with the teacher as the owner and the students as participantss

$messageId = $teacher->sendMessageTo($chatroom, $message); // will send the message to the given chatroom. If the teacher is not a participant of the chatroom, it will be added as a participant with the default role (e.g. `participant`).

// And you can also send messages as a bot / non-DB user (like an AI bot or external service)
$bot = new ChatParticipant('slackbot'); // `slackbot` is the key of the bot - it will be used to determine if a participant is in the chatroom
@todo
$bot->setName('My bot');
$bot->sendMessageTo($student, $message); // will send the message to the student
```

#### Send a message to a chatroom
```php
$messageId = $teacher->sendMessageTo($chatroom, $message);

// Alternative syntax
$messageId = $chatroom->sendMessageAs($teacher, $message); // will send the message as the teacher

// You can send a system message, which is a message that has neither an internal polymorphic relationship nor an external relationship/ID.
$messageId = $chatroom->sendMessage("Class postponed due to snow."); // will send a system message to the chatroom (not tied to a user)
```

#### Send a message to many users
```php

// will re-use the latest chatroom where only the students are in (and the teacher, but no one else), or, will create a new chatroom with both students and the teacher
$messageIds = $teacher->sendMessageToMany([[$studentA, $studentB]], $message); // One chatroom with the teacher and both students

$messageIds = $teacher->sendMessageToMany([$studentA, $studentB], $message); // Two chatrooms - one with the teacher and studentA, and one with the teacher and studentB


$messageIds = $teacher->sendMessageToMany([[$studentA, $administratorB], $channelC], $message); // will send the message to 2 chatrooms - one chatroom containing only the student, teacher, and administrator, and one to channelC itself. Note, this will create 2 new instances of the message, one for each chatroom. If in this example $channelC is a chatroom with the teacher, student, and administrator (e.g. same as first chatroom), only one message will be created, and the other will be ignored.

$messageIds = $teacher->sendMessageToMany([[$studentA, $studentB, $channelB]], $message); // Two chatrooms - one with the teacher and both students, and one to channelB itself. Note, this will create 2 new instances of the message, one for each chatroom. If in this example $channelB is a chatroom with the teacher, studentA, and studentB (e.g. same as first chatroom), only one message will be created, and the other will be ignored.

$messageIds = $teacher->sendMessageToMany([$studentA, $studentB, $channelB], $message); // Three chatrooms - one with the teacher and studentA, one with the teacher and studentB, and one to channelB itself. Note, this will create 3 new instances of the message, one for each chatroom. If in this example $channelB is a chatroom with the teacher, studentA, and studentB (e.g. same as first chatroom), only one message will be created, and the other will be ignored.
```

#### Send a message to many chatrooms
```php
$messageIds = $teacher->sendMessageToMany([$channelA, $channelB], $message); // Two chatrooms, two message instances will send the message to the given chatrooms - note, this will create a new instance of each message, per chatroom.

$messageIds = $teacher->sendMessageToMany([[$channelA, $channelB]], $message); // Two chatrooms, two message instances. Even though the notation is the same as if we want to group particiapnts for one chatroom, when a chatroom instance is passed, it will always be treated as its own chatroom. This means that if the teacher is in both chatrooms, it will not be added to the chatroom again, but the message will be sent to both chatrooms.
```

### Getting messages
#### Get messages from a chatroom
This will get messages from a given chatroom. This is usually what you want to call when you initially load a chatroom on the frontend.
```php
$messages = $chatroom->getMessages(); // will get all messages from the chatroom
$messages = $chatroom->getMessages(10); // will get the last 10 messages from the chatroom
$messages = $chatroom->getMessages(10, 0); // will get the last 10 messages from the chatroom, starting from the first message
```

#### Get messages visible to a given participant
```php
$messages = $student->getMessages();
```
LaravelChat will automatically filter the messages to only include those that can be read by the participant. This means that if a message is not visible to the participant, it will not be included in the result.

For example, you don't want a user that joined a chatroom recently to see messages that were sent before they joined the chatroom.

Additionally, if they are removed from the chatroom, they should not see any new messages sent to the chatroom after they were removed.

##### Scoping messages for a participant
You can also use the `canBeReadByParticipant` scope to filter messages that can be read by a given participant. The participant can be a `ChatParticipant` instance or any model that implements the `ChatParticipantInterface` and `IsChatParticipant` trait.

The messages will only include those that were created after a participant joined the chatroom, and will not include messages that were sent after the participant was removed from the chatroom.

```php
// A scope that filters messages based on the participant's visibility this will filter the messages to only include those that can be read by the participant.
ChatMessage::canBeReadByParticipant($participant)->get();
```

If you do not want to return any messages that were sent in chatrooms that the participant used to but no longer belongs in, set the second parameter to `false`. This will return no messages for chatrooms where the participant has left.

```php
ChatMessage::canBeReadByParticipant($participant, false)->get();
```

If you want to include messages sent to chatrooms before the participant joined, you can set the third parameter to `true`.

```php
ChatMessage::canBeReadByParticipant($participant, true, true)->get(); // Returns messages that were sent to chatrooms before the participant joined, but not messages sent to the chatroom after the participant was removed from the chatroom.
```

#### Get messages by a participant in a chatroom
```php
$messages = $chatroom->getMessagesBy($teacher); // will get all messages from the teacher in the chatroom
$messages = $chatroom->getMessagesBy($teacher, 10); // will get the last 10 messages from the teacher in the chatroom
$messages = $chatroom->getMessagesBy($teacher, 10, 0); // will get the last 10 messages from the teacher in the chatroom, starting from the first message
```

#### Get messages for a participant
This will return all messages for a given participant, regardless of the chatroom. The chatroom ID is included in the message object.

This is useful if we are rendering an inbox, for example.
```php
$messages = $teacher->getMessages(); // will get all messages from all chatrooms from the teacher
$messages = $teacher->getMessages(10); // will get the last 10 messages from all chatrooms from the teacher

$messages = $teacher->getLatestMessages(); // will get at most one message from each chatroom

$messages = $teacher->getLatestMessages(10); // will get at most one message from each chatroom, for a maximum of 10 messages/chatrooms
```

#### Get messages sent by a participant
This will return all messages sent by a given participant, regardless of the chatroom. The chatroom ID is included in the message object.
This is useful if we are rendering an inbox, for example.
```php
$messages = $teacher->getSentMessages(); // will get all messages sent by the teacher from all chatrooms
$messages = $teacher->getSentMessages(10); // will get the last 10 messages sent by the teacher from all chatrooms
```

### Adding participants to a chatroom
```php
$chatroom->addParticipant($student); // will add the student to the chatroom as a participant with the default role 'member'.

$chatroom->addParticipant($student, 'admin'); // will add the student to the chatroom as an admin

$chatroom->addParticipants([$studentA, $studentB], 'admin'); // will add the students to the chatroom as admins
```

### Removing participants from a chatroom
```php
$chatroom->removeParticipant($student); // will remove the student from the chatroom
$chatroom->removeParticipants([$studentA, $studentB]); // will remove the students from the chatroom
```

### Syncing participants
You can sync participants in a chatroom. This will remove all participants that are not in the given array, and add all participants that are not in the chatroom yet.
```php
$chatroom->syncParticipants([$studentA, $studentB]); // will remove all participants that are not in the given array, and add all participants that are not in the chatroom yet
$chatroom->syncParticipants([$studentA, $studentB], 'admin'); // will remove all participants that are not in the given array, and add all participants that are not in the chatroom yet, as admins. Existing participants will not be changed, so if the studentA is already in the chatroom as a member, they will remain a member, not an admin.
```

### Finding the best chatroom for a given set of participants
```php
Chatroom::getBestFor([$student, $teacher]); // will get the best chatroom for the given participants - the most resent chat that contains only these two participants. If no such chatroom exists, it will return null.

Chatroom::getOrCreateBestFor([$student, $teacher], [
    'name' => 'My custom chatroom name',
    'description' => 'My custom chatroom description',
    'metadata' => [
        'foo' => 'bar',
    ],
]); // will get the best chatroom for the given participants - the most resent chat that contains only these two participants. If no such chatroom exists, it will create a new chatroom with the given participants. The second parameter is optional and will be used to create the chatroom if it does not exist. The new chatroom will be created with the teacher as the owner and the student as a participant.
```

### Check if a participant is already in a chatroom
```php
$chatroom->hasParticipant($student); // will check if the student is already in the chatroom

$chatroom->hasOrHadParticipant($student); // will check if the student is already in the chatroom, or if they have previously been a member of the chatroom but were now removed.

// OR

$user->isParticipantIn($chatroom); // will check if the user is already in the chatroom

$user->isOrWasParticipantIn($chatroom); // will check if the user is already in the chatroom, or if they have previously been a member of the chatroom but were now removed.
```

### Check if a participant is connected via sockets to a given chatroom
Sometimes, it is useful to know if a participant is actively connected to a given chatroom via sockets. For example, if a user is not actively connected, you might want to send them a Web Push notification instead of a real-time message.

This currently only works with the `pusher` and `reverb` broadcast drivers. Internally, we call the [`get users`](https://pusher.com/docs/chatrooms/library_auth_reference/rest-api/#get-users) endpoint of the chatroom to check if the participant is currently connected to the chatroom.

```php
$user->isConnectedToChatroomViaSockets($chatroom);

# OR, using a ChatParticipant instance
$chatParticipant->is_connected;

# OR, if using the trait (note, this will check the model-specific broadcasting channel, not the chatroom channel)
$user->getIsConnectedViaSockets();
```

If you want to use this feature for your own models, you can use the `ConnectsToBroadcast` trait, which will provide the `getIsConnectedViaSockets` method. This method will return true if the participant is connected to the chatroom via sockets, false if they are not, and null if the connection could not be determined (e.g. if the broadcast driver does not support it).

Under the hood, it makes an API call to the websocket server to check if the participant is connected to the chatroom.

```php
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;

 protected function isConnected(): CastsAttribute
{
    return CastsAttribute::make(
        get: fn() => $this->getIsConnectedViaSockets()
    )->shouldCache();
}
```

This will check using the `broadcastChannel` of the model, if the user is currently connected to the websocket server on the given private channel. If the user is connected, it will return true, otherwise it will return false. If the connection could not be determined, it will return null.

If you want to check if the model is connected to a presence channel, you can pass the chatroom name and type as parameters to the method:
```php
$chatParticipant->getIsConnectedViaSockets(
    type: 'presence',
    channelName: $this->broadcastChannel(), // or any other chatroom name
    localId: 'id' // the local ID of the model, defaults to 'id'. This is the ID column on the model that will be used to check if the participant is connected to the chatroom - this is only relevant in presence channels. In private channels, we can only determine if the participant is connected or not, not which participant is connected.
);
```

### Check if a participant is notifiable
A participant can be notifiable if the model that it refers to uses the native Laravel `Notifiable` trait. In LaravelChat, `ChatParticipant` is not always related to a model that can be notified, so you can check if a `ChatParticipant` is notifiable by checking the `is_notifiable` attribute.

```php
if ($chatParticipant->is_notifiable) {
    // The participant_type is notifiable, so you can send notifications to it.
}
```

You may want to get all the notifiable participants in a chatroom, for example, to send them an individual notification about an event. You can do this by calling the `getNotifiableParticipants` method on the `Chatroom` model. This will return a collection of `ChatParticipant` models that are morphable to a model that uses the `Notifiable` trait.

```php
$notifiableParticipants = $chatroom->getNotifiableParticipants(); // will return a collection of ChatParticipant models that are morphable to a model that uses the Notifiable trait.

// Now you can send notifications to the notifiable participants
foreach ($notifiableParticipants as $participant) {
    $participant->notify(new \App\Notifications\NewMessageNotification($message));
}
```

## Events, listeners, and broadcasting
### New Message
When a new message is created, the `\Mmedia\LaravelChat\Events\MessageCreated::class` event is fired. This event contains the `Message` model instance, which is an instance of `ChatMessage`.

#### Event and chatroom broadcasting
When a new message is created, the `\Mmedia\LaravelChat\Events\MessageCreated::class` event is fired. This event contains the `Message` model instance, which is an instance of `ChatMessage`.

If you have broadcasting enabled, the event will be broadcasted to the chatroom chatroom. The chatroom name uses the Laravel Broadcasting convention, so it will be `Mmedia.LaravelChat.Models.Chatroom.{chatroom_id}`. This is a presence channel, so you can join it in your frontend application like this:
```typescript
import Echo from 'laravel-echo';

Echo.join('Mmedia.LaravelChat.Models.Chatroom.1').here((users) => {
    console.log("Users in chatroom:", users);
});
```
Refer to the [Laravel Broadcasting documentation](https://laravel.com/docs/broadcasting) for more information on how to set up broadcasting in your application and working with presence channels.

Note: even though you'll get messages here, its better to use the individual private channels for each chat participant becuase you'll be able to receive messages that are sent to the participant without joining each chatroom individually and opening multiple connections. Refer to the "Default notification" section below for more information on how to set this up.

#### Default listener
A default listener is provided to send a notification when a new message is created. You can customize this listener by changing the `chat.new_message_listener` config value in your `config/chat.php` file.

The default listener is `\Mmedia\LaravelChat\Listeners\SendMessageCreatedNotification::class`, which will send the "Default notification" (or whatever you configured) to the participants of the chatroom when a new message is created. It will NOT send a notification to the sender of the message.

#### Default notification
The default notification is `\Mmedia\LaravelChat\Notifications\NewMessage::class`, which will send a notification to each of the participants of the chatroom when a new message is created, except the sender of the message.

If you have broadcasting enabled, the notification will also broadcast the event to the notifiable via its private channel. The chatroom name uses the Laravel Broadcasting convention, so if your chat participant is a user, it will be something like `App.Models.User.{user_id}`.

The listener is the perfect place to check if the user is currently connected to the chatroom, and if they are not, send them a Web Push notification for example.

On a ChatParticipant, you can check `$chatParticipant->is_connected` to see if the participant is currently connected to the chatroom via sockets. Refer to the trait `ConnectsToBroadcast` for more information on how you can check connections on other channels.

### Using Chatrooms as notification channels
It can be helpful to use chatroom messages as a notification channel. Internally, LaravelChat does this when new participants are added or removed from a chatroom to create system-messages about these events.
You can use the `ChatroomChannel` notification channel to send notifications to a chatroom. This channel will automatically create a new message in the chatroom with the notification data.

```php
use Mmedia\LaravelChat\Notifications\ChatroomChannel;
use Mmedia\LaravelChat\Notifications\ChatroomChannelMessage;

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
By default, the notification will be sent as a system notification (not attached/"sent" by any particular participant) to the personal chatroom of the notifiable - e.g. the first chatroom that only contains them as a participant.

If you want to send the notification to a specific chatroom, you can set the `chatroom_id` property on the `ChatroomChannelMessage` instance.

```php
public function toChatroom(object $notifiable): ChatroomChannelMessage
{
    return (new ChatroomChannelMessage)
        ->message("My custom message")
        ->chatroomId(5); // The ID of the chatroom to send the notification
}
```

## API routes
A set of default API routes and controllers are provided to interact with the chatrooms and messages. You can customize these routes by publishing the package's routes file and modifying it as needed.
You can publish the routes file with:
```bash
php artisan vendor:publish --tag="laravel-chat-routes"
```

Currently the package uses the `api` and `auth:sanctum` middleware for the API routes. If you need to change this, you should override the routes in your application.

### GET /api/chatrooms
This route is used to get all chatrooms for the authenticated user, along with the participants and latest message.

This will also return chatrooms the user was a participant in but is no longer a participant of. The latest message returned is the latest message that can be read by the user.

### GET /api/chatrooms/{chatroom}
This route is used to get a specific chatroom by its ID for the authenticated user. It will return the chatroom with its participants and all messages visible to the user.

Authenticated users can only access chatrooms they are a participant of, or have been a participant of in the past.

### POST /api/messages
This route is used to send a message to a chatroom or other entity. The request body should contain the `to_entity_type`, `to_entity_id`, and `message` fields.

An existing chat between the sender and the recipient will be used, or a new one will be created if it does not exist.

- ``to_entity_type``: The type of the entity to send the message to (e.g. `Mmedia\LaravelChat\Models\Chatroom`, `Mmedia\LaravelChat\Models\ChatParticipant`, or anything that you implement the ChatParticipantInterface on can be used, e.g. `App\Models\User`).