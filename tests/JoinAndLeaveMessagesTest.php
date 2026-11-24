<?php

use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Listeners\SendMessageCreatedNotification;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

/** @return list<ChatParticipant> */
function membershipTestJoin(Chatroom $room, string ...$names): array
{
    return array_map(fn (string $name): ChatParticipant => ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'membership-test:'.$name,
        'display_name' => $name,
    ]), $names);
}

function membershipTestLeave(ChatParticipant ...$participants): void
{
    foreach ($participants as $participant) {
        $participant->delete();
    }
}

/** @return list<string> */
function membershipTestTimeline(Chatroom $room): array
{
    return $room->messages()->orderBy('id')->pluck('message')->all();
}

function membershipTestLatest(Chatroom $room): string
{
    return $room->messages()->orderByDesc('id')->value('message');
}

/** @return list<string> */
function membershipTestNames(int $count): array
{
    return ['Ana', 'Ben', 'Cara', ...array_map(fn (int $i): string => "Guest {$i}", range(4, $count))];
}

beforeEach(function () {
    $this->freezeSecond();
    $this->room = Chatroom::create(['name' => 'Tour group']);
});

describe('joining', function () {
    it('posts a single join as before', function () {
        membershipTestJoin($this->room, 'Ana');

        expect(membershipTestTimeline($this->room))->toBe(['Ana has joined the chat.']);
    });

    it('groups joins that happen close together into one message', function (array $names, string $expected) {
        membershipTestJoin($this->room, ...$names);

        expect(membershipTestTimeline($this->room))->toBe([$expected]);
    })->with([
        'two' => [['Ana', 'Ben'], 'Ana and Ben joined the chat.'],
        'three' => [['Ana', 'Ben', 'Cara'], 'Ana, Ben and Cara joined the chat.'],
        'four' => [['Ana', 'Ben', 'Cara', 'Dan'], 'Ana, Ben, Cara and 1 other joined the chat.'],
    ]);

    it('turns 20 joins into one message with the first names and a count', function () {
        membershipTestJoin($this->room, ...membershipTestNames(20));

        $message = $this->room->messages()->sole();
        expect($message->message)->toBe('Ana, Ben, Cara and 17 others joined the chat.')
            ->and($message->metadata['participants_joined'])->toBe(['names' => ['Ana', 'Ben', 'Cara'], 'count' => 20]);
    });

    it('keeps grouping while each join is within the window of the previous one', function () {
        membershipTestJoin($this->room, 'Ana');
        $this->travel(4)->seconds();
        membershipTestJoin($this->room, 'Ben');
        $this->travel(4)->seconds();
        membershipTestJoin($this->room, 'Cara');

        expect(membershipTestTimeline($this->room))->toBe(['Ana, Ben and Cara joined the chat.']);
    });

    it('starts a new message once the window has passed', function () {
        membershipTestJoin($this->room, 'Ana');
        $this->travel(6)->seconds();
        membershipTestJoin($this->room, 'Ben');

        expect(membershipTestTimeline($this->room))->toBe([
            'Ana has joined the chat.',
            'Ben has joined the chat.',
        ]);
    });

    it('starts a new message when someone posts between joins', function () {
        [$ana] = membershipTestJoin($this->room, 'Ana');
        $this->room->sendMessageAs($ana, 'Hi!');
        membershipTestJoin($this->room, 'Ben');

        expect(membershipTestTimeline($this->room))->toBe([
            'Ana has joined the chat.',
            'Hi!',
            'Ben has joined the chat.',
        ]);
    });

    it('does not add joins to an unrelated system message', function () {
        $this->room->sendMessage('Departure moved to 10:00.');
        membershipTestJoin($this->room, 'Ana');

        expect(membershipTestTimeline($this->room))->toBe([
            'Departure moved to 10:00.',
            'Ana has joined the chat.',
        ]);
    });

    it('lets everyone in the group see the grouped message', function () {
        config(['chat.can_see_messages_before_joined' => false]);
        $room = Chatroom::create(['name' => 'No history']);
        membershipTestJoin($room, 'Ana');
        $this->travel(2)->seconds();
        [$ben] = membershipTestJoin($room, 'Ben');

        expect($ben->visibleMessages()->pluck('message')->all())->toBe(['Ana and Ben joined the chat.']);
    });

    it('announces a grouped message once and updates it for each later join', function () {
        Event::fake([MessageCreated::class, MessageUpdated::class]);

        membershipTestJoin($this->room, 'Ana', 'Ben', 'Cara');

        Event::assertDispatchedTimes(MessageCreated::class, 1);
        Event::assertDispatchedTimes(MessageUpdated::class, 2);
        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event): bool => $event->message->message === 'Ana, Ben and Cara joined the chat.');
    });

    it('queues one new-message notification for a burst of joins', function () {
        Queue::fake([CallQueuedListener::class]);

        membershipTestJoin($this->room, 'Ana', 'Ben', 'Cara', 'Dan', 'Eve');

        expect(Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $listener): bool => $listener->class === SendMessageCreatedNotification::class))->toHaveCount(1);
    });

    it('posts one message per join when grouping is turned off', function () {
        config(['chat.join_and_leave_messages.group_within_seconds' => 0]);

        membershipTestJoin($this->room, 'Ana', 'Ben');

        expect(membershipTestTimeline($this->room))->toBe([
            'Ana has joined the chat.',
            'Ben has joined the chat.',
        ]);
    });

    it('shows as many names as configured', function () {
        config(['chat.join_and_leave_messages.names_shown' => 1]);

        membershipTestJoin($this->room, 'Ana', 'Ben', 'Cara');

        expect(membershipTestTimeline($this->room))->toBe(['Ana and 2 others joined the chat.']);
    });
});

describe('leaving', function () {
    it('posts a single leave as before', function () {
        [$ana] = membershipTestJoin($this->room, 'Ana');
        membershipTestLeave($ana);

        expect(membershipTestTimeline($this->room))->toBe([
            'Ana has joined the chat.',
            'Ana has left the chat.',
        ]);
    });

    it('groups leaves that happen close together into one message', function (array $names, string $expected) {
        membershipTestLeave(...membershipTestJoin($this->room, ...$names));

        expect(membershipTestTimeline($this->room))->toHaveCount(2)
            ->and(membershipTestLatest($this->room))->toBe($expected);
    })->with([
        'two' => [['Ana', 'Ben'], 'Ana and Ben left the chat.'],
        'three' => [['Ana', 'Ben', 'Cara'], 'Ana, Ben and Cara left the chat.'],
        'four' => [['Ana', 'Ben', 'Cara', 'Dan'], 'Ana, Ben, Cara and 1 other left the chat.'],
    ]);

    it('turns 20 leaves into one message with the first names and a count', function () {
        membershipTestLeave(...membershipTestJoin($this->room, ...membershipTestNames(20)));

        $message = $this->room->messages()->orderByDesc('id')->first();
        expect($message->message)->toBe('Ana, Ben, Cara and 17 others left the chat.')
            ->and($message->metadata['participants_left'])->toBe(['names' => ['Ana', 'Ben', 'Cara'], 'count' => 20])
            ->and($this->room->messages()->count())->toBe(2);
    });

    it('keeps grouping while each leave is within the window of the previous one', function () {
        [$ana, $ben, $cara] = membershipTestJoin($this->room, 'Ana', 'Ben', 'Cara');
        membershipTestLeave($ana);
        $this->travel(4)->seconds();
        membershipTestLeave($ben);
        $this->travel(4)->seconds();
        membershipTestLeave($cara);

        expect(membershipTestLatest($this->room))->toBe('Ana, Ben and Cara left the chat.')
            ->and($this->room->messages()->count())->toBe(2);
    });

    it('starts a new leave message once the window has passed', function () {
        [$ana, $ben] = membershipTestJoin($this->room, 'Ana', 'Ben');
        membershipTestLeave($ana);
        $this->travel(6)->seconds();
        membershipTestLeave($ben);

        expect(membershipTestTimeline($this->room))->toBe([
            'Ana and Ben joined the chat.',
            'Ana has left the chat.',
            'Ben has left the chat.',
        ]);
    });

    it('starts a new leave message when someone posts between leaves', function () {
        [$ana, $ben, $cara] = membershipTestJoin($this->room, 'Ana', 'Ben', 'Cara');
        membershipTestLeave($ana);
        $this->room->sendMessageAs($cara, 'Bye Ana!');
        membershipTestLeave($ben);

        expect(membershipTestTimeline($this->room))->toBe([
            'Ana, Ben and Cara joined the chat.',
            'Ana has left the chat.',
            'Bye Ana!',
            'Ben has left the chat.',
        ]);
    });

    it('lets the person who left see their own leave message right away', function () {
        Queue::fake([CallQueuedListener::class]);
        [$ana] = membershipTestJoin($this->room, 'Ana', 'Ben');

        membershipTestLeave($ana);

        expect($ana->visibleMessages()->pluck('message')->all())->toBe([
            'Ana has left the chat.',
            'Ana and Ben joined the chat.',
        ]);
    });

    it('lets everyone who left in the group see the grouped message', function () {
        [$ana, $ben] = membershipTestJoin($this->room, 'Ana', 'Ben', 'Cara');
        membershipTestLeave($ana);
        $this->travel(3)->seconds();
        membershipTestLeave($ben);

        expect($ana->visibleMessages()->pluck('message')->first())->toBe('Ana and Ben left the chat.')
            ->and($ben->visibleMessages()->pluck('message')->first())->toBe('Ana and Ben left the chat.');
    });

    it('queues one new-message notification for a burst of leaves', function () {
        $participants = membershipTestJoin($this->room, 'Ana', 'Ben', 'Cara', 'Dan', 'Eve');
        Queue::fake([CallQueuedListener::class]);

        membershipTestLeave(...$participants);

        expect(Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $listener): bool => $listener->class === SendMessageCreatedNotification::class))->toHaveCount(1);
    });

    it('posts one message per leave when grouping is turned off', function () {
        config(['chat.join_and_leave_messages.group_within_seconds' => 0]);

        membershipTestLeave(...membershipTestJoin($this->room, 'Ana', 'Ben'));

        expect(membershipTestTimeline($this->room))->toBe([
            'Ana has joined the chat.',
            'Ben has joined the chat.',
            'Ana has left the chat.',
            'Ben has left the chat.',
        ]);
    });
});

describe('turning system messages off', function () {
    it('posts no join or leave messages', function () {
        config(['chat.create_system_messages' => false]);

        membershipTestLeave(...membershipTestJoin($this->room, 'Ana', 'Ben'));

        expect(membershipTestTimeline($this->room))->toBe([])
            ->and($this->room->participantsWithTrashed()->count())->toBe(2);
    });

    it('still posts messages sent directly', function () {
        config(['chat.create_system_messages' => false]);

        $this->room->sendMessage('Departure moved to 10:00.');

        expect(membershipTestTimeline($this->room))->toBe(['Departure moved to 10:00.']);
    });
});
