# Usage
Laravel Chat allows you to add chat functionality to your Laravel application. It provides a simple and flexible API for sending and receiving messages between models.

## Difference from Chatify
I made this package because I disagreed with a fundamental design decision in Chatify, which was that a message can only have one sender and one recipient.

This package allows you to send messages to multiple recipients, and any models. You can also create multi-user chat rooms, and send messages to chatrooms.

## Other projects
- https://github.com/namumakwembo/wirechat - nice package but livewire heavy
- https://github.com/musonza/chat - nice but some code looks overly complex
-
```php
$user->sendMessageTo($otherUser, "Hello");

// Get the messages on the other side
$messages = $otherUser->getMessages();

// Get the sent messages
$messages = $user->getSentMessages();

// Get the sent messages to a specific user (across all chatrooms)
$messages = $user->getMessagesSentTo($otherUser);

// Get the sent messages to a specific chatroom
$messages = $user->getMessagesSentTo($chatroom);
```

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

This is the contents of the published config file:

```php
return [
// Coming soon
];
```

There are no views.

## Key concepts
- There is a `Chatroom` model that represents a chat chatroom. A chatroom can have many participants and many messages. There is only one `Chatroom` model.
- There is a `Message` model that represents a message in a chatroom. A message belongs to a sender and is intended for a chatroom. There is only one `Message` model.
- There is a `ChatParticipant` model that represents a participant in a chatroom. A participant can be pretty much anything. There is only one `ChatParticipant` model.
- Any model can implement the `ChatParticipant` interface to make it possible for that entity to send and receive messages. This is done by using the `ChatParticipant` trait.

## Sample use cases
- Many to many polymorphic model chat (e.g. teachers and students chat in a specific class chatroom)
- One to one polymorphic model chat (e.g. teacher and student chat in a private chat)
- One to one abstract chat (e.g. student (an actual model) and AI (a non-existent model) chat)
- Chatroom connected with external services (teachers and students chat, and Slack messages are forwarded/sent to the chatroom via a bot)

## High level
Note, this high level overview here does not cover permissions. Your app should handle permissions and access to chatrooms at a layer above this package.

### Your first chat

First, prepare the models to send and receive messages. The models must implement the `IsChatParticipant` interface. This interface is implemented by the `ChatParticipantInterface` interface, which is a contract for all chat participants.

#### First, setup your models
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

#### Then, send your first message
```php
$teacher = Teacher::find(1);
$student = Student::find(1);
$message = "Hello, how are you?";
$teacher->sendMessageTo($student, $message);
```
LaravelChat will automatically create a new chatroom with the teacher and student as participants, and send the message to the chatroom. The message will be stored in the database. The teacher will be the owner of the chatroom, and the student will be a participant.

#### On the students side, load the messages
The student will want to see the messages sent to them. This is usually what you want to call when you initially load a chatroom on the frontend.

```php
$student = Student::find(1);
return $student->getMessages(); // will get all messages from all chatrooms from the student, ordered from newest to oldest
```

A sample of the response might look like this:
```json
[
    {
        "uuid": "12345678-1234-5678-1234-567812345678",
        "message": "Hello, how are you?",
        "sender_id": 1,
        "channel_id": 1,
        "created_at": "2023-10-01T00:00:00.000000Z",
        "updated_at": "2023-10-01T00:00:00.000000Z"
    }
]
```

#### Send back a message
```php
$student = Student::find(1);
$chatroom = Chatroom::find(1); // Get the chatroom where the message was sent from channel_id in the original message
$message = "I'm fine, thank you!";
$student->sendMessageTo($chatroom, $message);

// Alternatively, you can send a message to the chatroom as a user
$chatroom->sendMessageAs($student, $message);

// You can also send the message directly to a user and it will re-use the latest chatroom that has only the teacher and student in it, (or create a new one if it doesn't exist - but in this case, it already should exist since its a reply)
$student->sendMessageTo($teacher, $message);
```

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

// Or
$user->isParticipantIn($chatroom); // will check if the user is already in the chatroom
```

### Check if a participant is connected via sockets to a given chatroom
Sometimes, it is useful to know if a participant is actively connected to a given chatroom via sockets. For example, if a user is not actively connected, you might want to send them a Web Push notification instead of a real-time message.

This currently only works with the `pusher` and `reverb` broadcast drivers. Internally, we will call the [`get users`](https://pusher.com/docs/chatrooms/library_auth_reference/rest-api/#get-users) endpoint of the chatroom to check if the participant is currently connected to the chatroom.

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

## Events, listeners, and broadcasting
### New Message

### Event and chatroom broadcasting
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

### Default listener
A default listener is provided to send a notification when a new message is created. You can customize this listener by changing the `chat.new_message_listener` config value in your `config/chat.php` file.

The default listener is `\Mmedia\LaravelChat\Listeners\SendMessageCreatedNotification::class`, which will send the "Default notification" (or whatever you configured) to the participants of the chatroom when a new message is created. It will NOT send a notification to the sender of the message.

### Default notification
The default notification is `\Mmedia\LaravelChat\Notifications\NewMessage::class`, which will send a notification to each of the participants of the chatroom when a new message is created, except the sender of the message.

If you have broadcasting enabled, the notification will also broadcast the event to the notifiable via its private channel. The chatroom name uses the Laravel Broadcasting convention, so if your chat participant is a user, it will be something like `App.Models.User.{user_id}`.

The listener is the perfect place to check if the user is currently connected to the chatroom, and if they are not, send them a Web Push notification for example.

On a ChatParticipant, you can check `$chatParticipant->is_connected` to see if the participant is currently connected to the chatroom via sockets. Refer to the trait `ConnectsToBroadcast` for more information on how you can check connections on other channels.

## API routes

### GET /api/chatrooms
This route is used to get all chatrooms for the authenticated user, along with the participants and latest message.

### GET /api/chatrooms/{chatroom}
This route is used to get a specific chatroom by its ID for the authenticated user. It will return the chatroom with its participants and all messages visible to the user.

### POST /api/messages
This route is used to send a message to a chatroom. The request body should contain the `to_entity_type`, `to_entity_id`, and `message` fields. An existing chat between the sender and the recipient will be used, or a new one will be created if it does not exist.
- ``to_entity_type``: The type of the entity to send the message to (e.g. `Mmedia\LaravelChat\Models\Chatroom`, `Mmedia\LaravelChat\Models\ChatParticipant`, or anything that implements the ChatParticipantInterface can be used like `App\Models\User`).