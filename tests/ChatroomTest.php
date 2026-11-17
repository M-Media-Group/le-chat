<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Http\Resources\ChatParticipantResource;
use Mmedia\LeChat\Http\Resources\ChatroomResource;
use Mmedia\LeChat\Http\Resources\MessageResource;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

it('can create a chatroom with a name', function () {
    $chatroom = Chatroom::create(['name' => 'General Chat']);

    expect($chatroom->name)->toBe('General Chat');
});

it('copies the global history default only when creating a room', function (bool $default) {
    config(['chat.can_see_messages_before_joined' => $default]);
    $room = Chatroom::create(['name' => 'History default']);
    $explicit = Chatroom::create(['can_see_messages_before_joined' => ! $default]);

    config(['chat.can_see_messages_before_joined' => ! $default]);
    $room->update(['name' => 'Renamed']);

    expect($room->fresh()->can_see_messages_before_joined)->toBe($default)
        ->and($explicit->fresh()->can_see_messages_before_joined)->toBe(! $default)
        ->and(Chatroom::create()->fresh()->can_see_messages_before_joined)->toBe(! $default);
})->with([false, true]);

it('saves room history defaults without changing existing memberships', function () {
    $room = Chatroom::create(['can_see_messages_before_joined' => false, 'metadata' => ['custom' => 'keep']]);
    $existing = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'existing']);

    $room->setCanSeeMessagesBeforeJoined(true);
    $new = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'new']);

    expect($room->fresh()->can_see_messages_before_joined)->toBeTrue()
        ->and($room->fresh()->metadata)->toBe(['custom' => 'keep'])
        ->and($existing->fresh()->can_see_messages_before_joined)->toBeFalse()
        ->and($new->fresh()->can_see_messages_before_joined)->toBeTrue();
});

it('backfills history settings for existing rooms and removed memberships', function (bool $default) {
    $migration = require __DIR__.'/../database/migrations/12_add_history_settings_to_chat_tables.php.stub';
    $migration->down();
    $roomId = DB::table('chatrooms')->insertGetId(['name' => 'Existing room', 'deleted_at' => now()]);
    $membershipId = DB::table('chat_participants')->insertGetId([
        'chatroom_id' => $roomId,
        'reference_id' => 'removed',
        'deleted_at' => now(),
    ]);
    config(['chat.can_see_messages_before_joined' => $default]);

    $migration->up();

    expect(Chatroom::withTrashed()->findOrFail($roomId)->can_see_messages_before_joined)->toBe($default)
        ->and(ChatParticipant::withTrashed()->findOrFail($membershipId)->can_see_messages_before_joined)->toBe($default);
    $rawRoomId = DB::table('chatrooms')->insertGetId(['name' => 'Database default']);
    expect((bool) DB::table('chatrooms')->where('id', $rawRoomId)->value('can_see_messages_before_joined'))->toBeFalse();

    $migration->down();
    expect(Schema::hasColumn('chatrooms', 'can_see_messages_before_joined'))->toBeFalse()
        ->and(Schema::hasColumn('chat_participants', 'can_see_messages_before_joined'))->toBeFalse();
})->with([false, true]);

it('covers participant history lookups with an index and removes it on rollback', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This query-plan assertion uses SQLite EXPLAIN QUERY PLAN.');
    }
    $identity = new ChatParticipant(['participant_type' => 'history-reader', 'participant_id' => 42]);
    $query = ChatParticipant::withTrashed()->ofParticipant($identity)
        ->where('can_see_messages_before_joined', true)->select('chatroom_id');
    $plan = DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings());

    expect(implode(' ', array_column($plan, 'detail')))
        ->toContain('COVERING INDEX chat_participants_history_lookup');

    $migration = require __DIR__.'/../database/migrations/12_add_history_settings_to_chat_tables.php.stub';
    $migration->down();
    $indexes = DB::select("PRAGMA index_list('chat_participants')");
    expect(array_column($indexes, 'name'))->not->toContain('chat_participants_history_lookup')
        ->toContain('chat_participant_unique', 'chat_participants_participant_type_participant_id_index');
});

it('rolls back the explicitly named history index with table prefixes', function (bool $prefixIndexes) {
    config(['database.connections.prefixed_history' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'tenant_',
        'prefix_indexes' => $prefixIndexes,
    ]]);
    $defaultConnection = DB::getDefaultConnection();

    try {
        DB::setDefaultConnection('prefixed_history');
        Schema::clearResolvedInstance('db.schema');
        Schema::create('chatrooms', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('chat_participants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chatroom_id');
            $table->morphs('participant');
        });
        $migration = require __DIR__.'/../database/migrations/12_add_history_settings_to_chat_tables.php.stub';

        $migration->up();
        expect(Schema::hasIndex('chat_participants', 'chat_participants_history_lookup'))->toBeTrue();
        $migration->down();
        $migration->down();

        expect(Schema::hasIndex('chat_participants', 'chat_participants_history_lookup'))->toBeFalse()
            ->and(Schema::hasColumn('chat_participants', 'can_see_messages_before_joined'))->toBeFalse()
            ->and(Schema::hasColumn('chatrooms', 'can_see_messages_before_joined'))->toBeFalse()
            ->and(Schema::hasTable('chat_participants'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($defaultConnection);
        Schema::clearResolvedInstance('db.schema');
        DB::purge('prefixed_history');
    }
})->with([false, true]);

it('safely rolls back incomplete history schema and tolerates repeated rollback', function (string $state) {
    $migration = require __DIR__.'/../database/migrations/12_add_history_settings_to_chat_tables.php.stub';
    $roomId = DB::table('chatrooms')->insertGetId(['name' => 'Keep this room']);
    if ($state === 'already rolled back') {
        $migration->down();
    } elseif ($state === 'room column missing') {
        Schema::table('chatrooms', fn ($table) => $table->dropColumn('can_see_messages_before_joined'));
    } else {
        Schema::table('chat_participants', fn ($table) => $table->dropIndex('chat_participants_history_lookup'));
        if ($state === 'membership column missing') {
            Schema::table('chat_participants', fn ($table) => $table->dropColumn('can_see_messages_before_joined'));
        }
    }

    $migration->down();
    $migration->down();

    expect(Schema::hasColumn('chatrooms', 'can_see_messages_before_joined'))->toBeFalse()
        ->and(Schema::hasColumn('chat_participants', 'can_see_messages_before_joined'))->toBeFalse()
        ->and(Schema::hasColumn('chat_participants', 'participant_id'))->toBeTrue()
        ->and(DB::table('chatrooms')->where('id', $roomId)->value('name'))->toBe('Keep this room');

    $migration->up();
    expect(Schema::hasColumn('chatrooms', 'can_see_messages_before_joined'))->toBeTrue()
        ->and(Schema::hasColumn('chat_participants', 'can_see_messages_before_joined'))->toBeTrue();
})->with(['index missing', 'membership column missing', 'room column missing', 'already rolled back']);

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

it('loads the latest message visible during the participant membership', function () {
    $room = Chatroom::create(['name' => 'Visible latest message']);
    $participant = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'agent:reader',
    ]);
    $joinedAt = now()->subMinutes(3);
    $participant->forceFill(['created_at' => $joinedAt])->saveQuietly();

    $beforeJoining = $room->sendMessageAs($participant, 'Before joining');
    $beforeJoining->forceFill(['created_at' => $joinedAt->copy()->subMinute()])->saveQuietly();

    $visible = $room->sendMessageAs($participant, 'Visible message', [
        'metadata' => ['payload' => 'large'],
    ]);
    $visible->forceFill(['created_at' => $joinedAt->copy()->addMinute()])->saveQuietly();

    $afterLeaving = $room->sendMessageAs($participant, 'After leaving');
    $leftAt = $joinedAt->copy()->addMinutes(2);
    $afterLeaving->forceFill(['created_at' => $joinedAt->copy()->addMinutes(3)])->saveQuietly();
    $participant->forceFill(['deleted_at' => $leftAt])->saveQuietly();

    $loadedRoom = Chatroom::withLatestMessageFor($participant)->findOrFail($room->getKey());

    expect($loadedRoom->latestMessage->getKey())->toBe($visible->getKey())
        ->and($loadedRoom->latestMessage->getAttributes())->toHaveKey('metadata');
});

it('serializes a loaded missing latest message as null', function () {
    $room = Chatroom::create(['name' => 'No messages yet']);

    $payload = ChatroomResource::make($room->load('latestMessage'))->resolve();

    expect($payload['latest_message'])->toBeNull();
});

it('uses the matching resource class for each loaded collection', function () {
    $room = Chatroom::create(['name' => 'Resource collections']);
    $room->sendMessage('A message');
    ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'agent:reader',
    ]);

    $payload = ChatroomResource::make($room->load(['messages', 'participants']))->toArray(request());

    expect($payload['messages'])->toBeInstanceOf(AnonymousResourceCollection::class)
        ->and($payload['messages']->collects)->toBe(MessageResource::class)
        ->and($payload['participants'])->toBeInstanceOf(AnonymousResourceCollection::class)
        ->and($payload['participants']->collects)->toBe(ChatParticipantResource::class);
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

it('updates previews and unread counts from membership history without marking it read', function () {
    Event::fake();
    $room = Chatroom::create(['can_see_messages_before_joined' => false]);
    $reader = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'reader']);
    $reader->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    $old = $room->sendMessage('Earlier history');
    $old->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();

    $hidden = Chatroom::withLatestMessageFor($reader)->withUnreadMessagesCountFor($reader)->findOrFail($room->id);
    expect($hidden->latestMessage)->toBeNull()->and($hidden->unread_messages_count)->toBe(0);

    $reader->setCanSeeMessagesBeforeJoined(true);
    $visible = Chatroom::withLatestMessageFor($reader)->withUnreadMessagesCountFor($reader)->findOrFail($room->id);
    expect($visible->latestMessage->id)->toBe($old->id)
        ->and($visible->unread_messages_count)->toBe(1)
        ->and($reader->fresh()->read_at)->toBeNull();
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

it('announces each participant join once without announcing unchanged membership', function () {
    $room = Chatroom::create(['name' => 'Join announcements']);
    $sourceRoom = Chatroom::create(['name' => 'Source room']);
    $source = ChatParticipant::create([
        'chatroom_id' => $sourceRoom->getKey(),
        'reference_id' => 'member:joining',
        'display_name' => 'Joining member',
    ]);
    Event::fake([ParticipantCreated::class]);

    $room->syncParticipants([$source]);

    Event::assertDispatchedTimes(ParticipantCreated::class, 1);

    $room->syncParticipants([$source]);
    $room->addParticipant($source);

    Event::assertDispatchedTimes(ParticipantCreated::class, 1);
    expect($room->participantsWithTrashed()->count())->toBe(1);

    $room->syncParticipants([]);
    $room->syncParticipants([$source]);

    Event::assertDispatchedTimes(ParticipantCreated::class, 2);
    expect($room->participants()->count())->toBe(1)
        ->and($room->participantsWithTrashed()->count())->toBe(1);
});
