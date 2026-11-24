<?php

use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Listeners\SendMessageCreatedNotification;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

function joinMessagesTestJoin(Chatroom $room, string ...$names): ChatParticipant
{
    foreach ($names as $name) {
        $participant = ChatParticipant::create([
            'chatroom_id' => $room->getKey(),
            'reference_id' => 'join-test:'.$name,
            'display_name' => $name,
        ]);
    }

    return $participant;
}

/** @return list<string> */
function joinMessagesTestTimeline(Chatroom $room): array
{
    return $room->messages()->orderBy('id')->pluck('message')->all();
}

beforeEach(function () {
    $this->freezeSecond();
    $this->room = Chatroom::create(['name' => 'Tour group']);
});

it('posts a single join as before', function () {
    joinMessagesTestJoin($this->room, 'Ana');

    expect(joinMessagesTestTimeline($this->room))->toBe(['Ana has joined the chat.']);
});

it('groups joins that happen close together into one message', function (array $names, string $expected) {
    joinMessagesTestJoin($this->room, ...$names);

    expect(joinMessagesTestTimeline($this->room))->toBe([$expected]);
})->with([
    'two' => [['Ana', 'Ben'], 'Ana and Ben joined the chat.'],
    'three' => [['Ana', 'Ben', 'Cara'], 'Ana, Ben and Cara joined the chat.'],
    'four' => [['Ana', 'Ben', 'Cara', 'Dan'], 'Ana, Ben, Cara and 1 other joined the chat.'],
]);

it('turns 20 joins into one message with the first names and a count', function () {
    $names = ['Ana', 'Ben', 'Cara', ...array_map(fn (int $i): string => "Guest {$i}", range(4, 20))];

    joinMessagesTestJoin($this->room, ...$names);

    $message = $this->room->messages()->sole();
    expect($message->message)->toBe('Ana, Ben, Cara and 17 others joined the chat.')
        ->and($message->metadata['participants_joined'])->toBe(['names' => ['Ana', 'Ben', 'Cara'], 'count' => 20]);
});

it('keeps grouping while each join is within the window of the previous one', function () {
    joinMessagesTestJoin($this->room, 'Ana');
    $this->travel(4)->seconds();
    joinMessagesTestJoin($this->room, 'Ben');
    $this->travel(4)->seconds();
    joinMessagesTestJoin($this->room, 'Cara');

    expect(joinMessagesTestTimeline($this->room))->toBe(['Ana, Ben and Cara joined the chat.']);
});

it('starts a new message once the window has passed', function () {
    joinMessagesTestJoin($this->room, 'Ana');
    $this->travel(6)->seconds();
    joinMessagesTestJoin($this->room, 'Ben');

    expect(joinMessagesTestTimeline($this->room))->toBe([
        'Ana has joined the chat.',
        'Ben has joined the chat.',
    ]);
});

it('starts a new message when someone posts between joins', function () {
    $ana = joinMessagesTestJoin($this->room, 'Ana');
    $this->room->sendMessageAs($ana, 'Hi!');
    joinMessagesTestJoin($this->room, 'Ben');

    expect(joinMessagesTestTimeline($this->room))->toBe([
        'Ana has joined the chat.',
        'Hi!',
        'Ben has joined the chat.',
    ]);
});

it('does not add joins to an unrelated system message', function () {
    $this->room->sendMessage('Departure moved to 10:00.');
    joinMessagesTestJoin($this->room, 'Ana');

    expect(joinMessagesTestTimeline($this->room))->toBe([
        'Departure moved to 10:00.',
        'Ana has joined the chat.',
    ]);
});

it('lets everyone in the group see the grouped message', function () {
    config(['chat.can_see_messages_before_joined' => false]);
    $room = Chatroom::create(['name' => 'No history']);
    joinMessagesTestJoin($room, 'Ana');
    $this->travel(2)->seconds();
    $ben = joinMessagesTestJoin($room, 'Ben');

    expect($ben->visibleMessages()->pluck('message')->all())->toBe(['Ana and Ben joined the chat.']);
});

it('announces a grouped message once and updates it for each later join', function () {
    Event::fake([MessageCreated::class, MessageUpdated::class]);

    joinMessagesTestJoin($this->room, 'Ana', 'Ben', 'Cara');

    Event::assertDispatchedTimes(MessageCreated::class, 1);
    Event::assertDispatchedTimes(MessageUpdated::class, 2);
    Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event): bool => $event->message->message === 'Ana, Ben and Cara joined the chat.');
});

it('queues one new-message notification for a burst of joins', function () {
    Queue::fake([CallQueuedListener::class]);

    joinMessagesTestJoin($this->room, 'Ana', 'Ben', 'Cara', 'Dan', 'Eve');

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $listener): bool => $listener->class === SendMessageCreatedNotification::class);
    expect(Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $listener): bool => $listener->class === SendMessageCreatedNotification::class))->toHaveCount(1);
});

it('posts one message per join when grouping is turned off', function () {
    config(['chat.join_messages.group_within_seconds' => 0]);

    joinMessagesTestJoin($this->room, 'Ana', 'Ben');

    expect(joinMessagesTestTimeline($this->room))->toBe([
        'Ana has joined the chat.',
        'Ben has joined the chat.',
    ]);
});

it('shows as many names as configured', function () {
    config(['chat.join_messages.names_shown' => 1]);

    joinMessagesTestJoin($this->room, 'Ana', 'Ben', 'Cara');

    expect(joinMessagesTestTimeline($this->room))->toBe(['Ana and 2 others joined the chat.']);
});

it('posts a leave message', function () {
    joinMessagesTestJoin($this->room, 'Ana')->delete();

    expect(joinMessagesTestTimeline($this->room))->toBe([
        'Ana has joined the chat.',
        'Ana has left the chat.',
    ]);
});

it('posts no join or leave messages when system messages are turned off', function () {
    config(['chat.create_system_messages' => false]);

    joinMessagesTestJoin($this->room, 'Ana', 'Ben')->delete();

    expect(joinMessagesTestTimeline($this->room))->toBe([])
        ->and($this->room->participantsWithTrashed()->count())->toBe(2);
});

it('still posts messages sent directly when system messages are turned off', function () {
    config(['chat.create_system_messages' => false]);

    $this->room->sendMessage('Departure moved to 10:00.');

    expect(joinMessagesTestTimeline($this->room))->toBe(['Departure moved to 10:00.']);
});
