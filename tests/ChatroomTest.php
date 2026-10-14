<?php

use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

it('can create a chatroom with a name', function () {
    $chatroom = Chatroom::create(['name' => 'General Chat']);

    expect($chatroom->name)->toBe('General Chat');
});

it('finds chatrooms containing every requested participant even with extras', function () {
    $matchingRoom = Chatroom::create(['name' => 'Matching room']);
    $partialRoom = Chatroom::create(['name' => 'Partial room']);

    ChatParticipant::create([
        'chatroom_id' => $matchingRoom->getKey(),
        'reference_id' => 'agent:first',
    ]);
    ChatParticipant::create([
        'chatroom_id' => $matchingRoom->getKey(),
        'reference_id' => 'agent:second',
    ]);
    ChatParticipant::create([
        'chatroom_id' => $matchingRoom->getKey(),
        'reference_id' => 'agent:extra',
    ]);
    ChatParticipant::create([
        'chatroom_id' => $partialRoom->getKey(),
        'reference_id' => 'agent:first',
    ]);

    $participants = [
        new ChatParticipant(['reference_id' => 'agent:first']),
        new ChatParticipant(['reference_id' => 'agent:second']),
    ];

    $roomIds = Chatroom::havingParticipants($participants)->orderBy('id')->pluck('id')->all();

    expect($roomIds)->toBe([$matchingRoom->getKey()]);
});

it('requires the exact participant count for exact matching', function () {
    $exactRoom = Chatroom::create(['name' => 'Exact room']);
    $roomWithExtra = Chatroom::create(['name' => 'Room with extra participant']);

    foreach ([$exactRoom, $roomWithExtra] as $room) {
        ChatParticipant::create([
            'chatroom_id' => $room->getKey(),
            'reference_id' => 'agent:first',
        ]);
        ChatParticipant::create([
            'chatroom_id' => $room->getKey(),
            'reference_id' => 'agent:second',
        ]);
    }

    ChatParticipant::create([
        'chatroom_id' => $roomWithExtra->getKey(),
        'reference_id' => 'agent:extra',
    ]);
    $participants = [
        new ChatParticipant(['reference_id' => 'agent:first']),
        new ChatParticipant(['reference_id' => 'agent:second']),
    ];

    $roomIds = Chatroom::havingExactlyParticipants($participants)->orderBy('id')->pluck('id')->all();

    expect($roomIds)->toBe([$exactRoom->getKey()]);
});

it('includes soft-deleted participants only when requested', function () {
    $room = Chatroom::create(['name' => 'Room with a departed participant']);
    $participant = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'agent:departed',
    ]);
    $participant->delete();

    $identity = new ChatParticipant(['reference_id' => 'agent:departed']);

    expect(Chatroom::havingParticipants([$identity])->exists())->toBeFalse()
        ->and(Chatroom::havingParticipants([$identity], true)->pluck('id')->all())->toBe([$room->getKey()]);
});

it('sends participant and system messages and exposes the latest message', function () {
    $room = Chatroom::create(['name' => 'Message room']);
    $sender = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'agent:sender',
    ]);

    $systemMessage = $room->sendMessage('System notice');
    $participantMessage = $room->sendMessageAs($sender, 'Participant message');
    ChatMessage::query()
        ->where('chatroom_id', $room->getKey())
        ->whereKeyNot($participantMessage->getKey())
        ->update(['created_at' => now()->subMinute()]);

    $freshRoom = $room->fresh();

    expect($freshRoom->messages()->whereIn('id', [$systemMessage->getKey(), $participantMessage->getKey()])->count())->toBe(2)
        ->and($freshRoom->latestMessage->getKey())->toBe($participantMessage->getKey())
        ->and($systemMessage->sender_id)->toBeNull()
        ->and($participantMessage->sender_id)->toBe($sender->getKey());
});

it('adds, finds, marks read, and removes participants', function () {
    $room = Chatroom::create(['name' => 'Participant room']);
    $sourceRoom = Chatroom::create(['name' => 'Source room']);
    $firstSource = ChatParticipant::create([
        'chatroom_id' => $sourceRoom->getKey(),
        'reference_id' => 'member:first',
    ]);
    $secondSource = ChatParticipant::create([
        'chatroom_id' => $sourceRoom->getKey(),
        'reference_id' => 'member:second',
    ]);

    $first = $room->addParticipant($firstSource);
    $additional = $room->addParticipants([$secondSource]);
    $message = $room->sendMessageAs($first, 'Read this message');
    $wasMarkedRead = $room->markAsReadBy($first, $message);

    expect($room->hasParticipant($first))->toBeTrue()
        ->and($room->participant($first)?->getKey())->toBe($first->getKey())
        ->and($wasMarkedRead)->toBeTrue()
        ->and($first->fresh()->read_at)->not->toBeNull();

    $room->removeParticipant($first);
    $room->removeParticipants($additional);

    expect($room->participants()->count())->toBe(0)
        ->and($room->participantsWithTrashed()->count())->toBe(2)
        ->and($room->hasParticipant($first))->toBeFalse()
        ->and($room->hasOrHadParticipant($first))->toBeTrue()
        ->and($room->participant($first))->toBeNull()
        ->and($room->participant($first, true)?->getKey())->toBe($first->getKey());
});

it('counts unread messages for a participant', function () {
    $room = Chatroom::create(['name' => 'Unread messages room']);
    $reader = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'member:reader',
    ]);
    $sender = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'member:sender',
    ]);
    $room->messages()->whereNull('sender_id')->delete();
    $message = $room->sendMessageAs($sender, 'Unread message');

    $unreadCount = Chatroom::withUnreadMessagesCountFor($reader)->findOrFail($room->getKey())->unread_messages_count;
    $room->markAsReadBy($reader, $message);
    $reader->refresh();
    $readCount = Chatroom::withUnreadMessagesCountFor($reader)->findOrFail($room->getKey())->unread_messages_count;

    expect($unreadCount)->toBe(1)
        ->and($readCount)->toBe(0);
});

it('syncs participants while retaining existing participants', function () {
    $chatroom = Chatroom::create(['name' => 'General Chat']);
    $otherRoom = Chatroom::create(['name' => 'Other Chat']);
    $existingParticipant = ChatParticipant::create([
        'chatroom_id' => $otherRoom->getKey(),
        'reference_id' => 'existing',
    ]);
    $newParticipant = ChatParticipant::create([
        'chatroom_id' => $otherRoom->getKey(),
        'reference_id' => 'new',
    ]);
    $chatroom->addParticipant($existingParticipant);

    $result = $chatroom->syncParticipants([$existingParticipant, $newParticipant]);

    expect($chatroom->participants()->count())->toBe(2)
        ->and($result['added'])->toHaveCount(1)
        ->and($result['removed'])->toBe([]);
});
