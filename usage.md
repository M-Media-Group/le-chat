# Usage
Laravel Chat allows you to add chat functionality to your Laravel application. It provides a simple and flexible API for sending and receiving messages between models.

## Difference from Chatify
I made this package because I disagreed with a fundamental design decision in Chatify, which was that a message can only have one sender and one recipient.

This package allows you to send messages to multiple recipients, and any models. You can also create multi-user chat rooms, and send messages to channels.

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

// Get the sent messages to a specific user (across all channels)
$messages = $user->getMessagesSentTo($otherUser);

// Get the sent messages to a specific channel
$messages = $user->getMessagesSentTo($channel);
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
- There is a `Channel` model that represents a chat channel. A channel can have many participants and many messages. There is only one `Channel` model.
- There is a `Message` model that represents a message in a channel. A message belongs to a sender and is intended for a channel. There is only one `Message` model.
- There is a `ChatParticipant` model that represents a participant in a channel. A participant can be pretty much anything. There is only one `ChatParticipant` model.
- Any model can implement the `ChatParticipant` interface to make it possible for that entity to send and receive messages. This is done by using the `ChatParticipant` trait.

## Sample use cases
- Many to many polymorphic model chat (e.g. teachers and students chat in a specific class chatroom)
- One to one polymorphic model chat (e.g. teacher and student chat in a private chat)
- One to one abstract chat (e.g. student (an actual model) and AI (a non-existent model) chat)
- Chatroom connected with external services (teachers and students chat, and Slack messages are forwarded/sent to the channel via a bot)

## High level
Note, this high level overview here does not cover permissions. Your app should handle permissions and access to channels at a layer above this package.

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
LaravelChat will automatically create a new channel with the teacher and student as participants, and send the message to the channel. The message will be stored in the database. The teacher will be the owner of the channel, and the student will be a participant.

#### On the students side, load the messages
The student will want to see the messages sent to them. This is usually what you want to call when you initially load a channel on the frontend.

```php
$student = Student::find(1);
return $student->getMessages(); // will get all messages from all channels from the student, ordered from newest to oldest
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
$channel = Channel::find(1); // Get the channel where the message was sent from channel_id in the original message
$message = "I'm fine, thank you!";
$student->sendMessageTo($channel, $message);

// Alternatively, you can send a message to the channel as a user
$channel->sendMessageAs($student, $message);

// You can also send the message directly to a user and it will re-use the latest channel that has only the teacher and student in it, (or create a new one if it doesn't exist - but in this case, it already should exist since its a reply)
$student->sendMessageTo($teacher, $message);
```

### Sending messages
First, prepare the models to send and receive messages. The models must implement the `ChatParticipant` interface. This interface is implemented by the `ChatParticipant` class, which is a base class for all chat participants.

```php
class Teacher extends Model
{
    use ChatParticipant;

    // Your model code here
}

class Student extends Model
{
    use ChatParticipant;

    // Your model code here
}
```

#### Send a message to a model
```php

// will re-use the latest channel where only the teacher and student are in, or if not found, will create a new channel with both participants. The new channel will be created with the teacher as the owner and the student as a participant
$messageId = $teacher->sendMessageTo($student, $message);

$messageId = $teacher->sendMessageTo($student, $message, true); // will create a new channel even if one exists

$messageId = $teacher->sendMessageTo($student, $message, true, [
    'name' => 'My custom channel name',
    'description' => 'My custom channel description',
    'metadata' => [
        'foo' => 'bar',
    ],
]); // will create a new channel with the given name, description and metadata

$messageId = $teacher->sendMessageTo([$studentA, $studentB], $message); // will re-use the latest channel where only the teacher and both students are in, or if not found, will create a new channel with both participants. The new channel will be created with the teacher as the owner and the students as participantss

$messageId = $teacher->sendMessageTo($channel, $message); // will send the message to the given channel. If the teacher is not a participant of the channel, it will be added as a participant with the default role (e.g. `participant`).

// And you can also send messages as a bot / non-DB user (like an AI bot or external service)
$bot = new ChatParticipant('slackbot'); // `slackbot` is the key of the bot - it will be used to determine if a participant is in the channel
$bot->setName('My bot');
$bot->sendMessageTo($student, $message); // will send the message to the student
```

#### Send a message to a channel
```php
$messageId = $teacher->sendMessageTo($channel, $message);

// Alternative syntax
$messageId = $channel->sendMessageAs($teacher, $message); // will send the message as the teacher

// You can send a system message, which is a message that has neither an internal polymorphic relationship nor an external relationship/ID.
$messageId = $channel->sendMessage("Class postponed due to snow."); // will send a system message to the channel (not tied to a user)
```

#### Send a message to many users
```php

// will re-use the latest channel where only the students are in (and the teacher, but no one else), or, will create a new channel with both students and the teacher
$messageIds = $teacher->sendMessageToMany([[$studentA, $studentB]], $message); // One channel with the teacher and both students

$messageIds = $teacher->sendMessageToMany([$studentA, $studentB], $message); // Two channels - one with the teacher and studentA, and one with the teacher and studentB


$messageIds = $teacher->sendMessageToMany([[$studentA, $administratorB], $channelC], $message); // will send the message to 2 channels - one channel containing only the student, teacher, and administrator, and one to channelC itself. Note, this will create 2 new instances of the message, one for each channel. If in this example $channelC is a channel with the teacher, student, and administrator (e.g. same as first channel), only one message will be created, and the other will be ignored.

$messageIds = $teacher->sendMessageToMany([[$studentA, $studentB, $channelB]], $message); // Two channels - one with the teacher and both students, and one to channelB itself. Note, this will create 2 new instances of the message, one for each channel. If in this example $channelB is a channel with the teacher, studentA, and studentB (e.g. same as first channel), only one message will be created, and the other will be ignored.

$messageIds = $teacher->sendMessageToMany([$studentA, $studentB, $channelB], $message); // Three channels - one with the teacher and studentA, one with the teacher and studentB, and one to channelB itself. Note, this will create 3 new instances of the message, one for each channel. If in this example $channelB is a channel with the teacher, studentA, and studentB (e.g. same as first channel), only one message will be created, and the other will be ignored.
```

#### Send a message to many channels
```php
$messageIds = $teacher->sendMessageToMany([$channelA, $channelB], $message); // Two channels, two message instances will send the message to the given channels - note, this will create a new instance of each message, per channel.

$messageIds = $teacher->sendMessageToMany([[$channelA, $channelB]], $message); // Two channels, two message instances. Even though the notation is the same as if we want to group particiapnts for one channel, when a channel instance is passed, it will always be treated as its own channel. This means that if the teacher is in both channels, it will not be added to the channel again, but the message will be sent to both channels.
```

### Getting messages
#### Get messages from a channel
This will get messages from a given channel. This is usually what you want to call when you initially load a channel on the frontend.
```php
$messages = $channel->getMessages(); // will get all messages from the channel
$messages = $channel->getMessages(10); // will get the last 10 messages from the channel
$messages = $channel->getMessages(10, 0); // will get the last 10 messages from the channel, starting from the first message
```
#### Get messages by a participant in a channel
```php
$messages = $channel->getMessagesBy($teacher); // will get all messages from the teacher in the channel
$messages = $channel->getMessagesBy($teacher, 10); // will get the last 10 messages from the teacher in the channel
$messages = $channel->getMessagesBy($teacher, 10, 0); // will get the last 10 messages from the teacher in the channel, starting from the first message
```

#### Get messages for a participant
This will return all messages for a given participant, regardless of the channel. The channel ID is included in the message object.

This is useful if we are rendering an inbox, for example.
```php
$messages = $teacher->getMessages(); // will get all messages from all channels from the teacher
$messages = $teacher->getMessages(10); // will get the last 10 messages from all channels from the teacher

$messages = $teacher->getLatestMessages(); // will get at most one message from each channel

$messages = $teacher->getLatestMessages(10); // will get at most one message from each channel, for a maximum of 10 messages/channels
```

#### Get messages sent by a participant
This will return all messages sent by a given participant, regardless of the channel. The channel ID is included in the message object.
This is useful if we are rendering an inbox, for example.
```php
$messages = $teacher->getSentMessages(); // will get all messages sent by the teacher from all channels
$messages = $teacher->getSentMessages(10); // will get the last 10 messages sent by the teacher from all channels
```

### Adding participants to a channel
```php
$channel->addParticipant($student); // will add the student to the channel

$channel->addParticipant($student, 'admin'); // will add the student to the channel as an admin

$channel->addParticipants([$studentA, $studentB], 'admin'); // will add the students to the channel as admins
```

### Finding the best channel for a given set of participants
```php
Channel::getBestFor([$student, $teacher]); // will get the best channel for the given participants - the most resent chat that contains only these two participants. If no such channel exists, it will return null.

Channel::getOrCreateBestFor([$student, $teacher], [
    'name' => 'My custom channel name',
    'description' => 'My custom channel description',
    'metadata' => [
        'foo' => 'bar',
    ],
]); // will get the best channel for the given participants - the most resent chat that contains only these two participants. If no such channel exists, it will create a new channel with the given participants. The second parameter is optional and will be used to create the channel if it does not exist. The new channel will be created with the teacher as the owner and the student as a participant.
```

### Check if a participant is already in a channel
```php
$channel->hasParticipant($student); // will check if the student is already in the channel

// Or
$user->isParticipantIn($channel); // will check if the user is already in the channel
```