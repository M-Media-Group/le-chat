<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Events\ParticipantDeleted;
use Mmedia\LeChat\Http\Resources\ChatParticipantResource;
use Mmedia\LeChat\Listeners\SendParticipantCreatedNotification;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;
use Mmedia\LeChat\Support\ParticipantMessages;
use Mmedia\LeChat\Traits\IsChatParticipant;

/** @implements ChatParticipantInterface<ChatParticipantTestOwner> */
class ChatParticipantTestOwner extends Model implements ChatParticipantInterface
{
    use IsChatParticipant;

    protected $table = 'chat_participant_test_owners';

    protected $guarded = [];
}

/** @implements ChatParticipantInterface<ChatParticipantTestMessageOwner> */
class ChatParticipantTestMessageOwner extends Model implements ChatParticipantInterface
{
    use IsChatParticipant;

    protected $table = 'chat_messages';

    protected $guarded = [];
}

class ChatParticipantTestCustomLeaveMembership extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'left_at';

    protected $table = 'chat_participants';
}

beforeEach(function () {
    Schema::create('chat_participant_test_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
    });
});

it('uses a custom soft-delete column for the through membership leave boundary', function () {
    Event::fake([MessageCreated::class, ParticipantCreated::class, ParticipantDeleted::class]);
    $this->freezeSecond();
    Schema::table('chat_participants', fn (Blueprint $table) => $table->timestamp('left_at')->nullable());
    $room = Chatroom::create(['can_see_messages_before_joined' => false]);
    $owner = ChatParticipantTestOwner::create();
    $membership = $room->addParticipant($owner);
    $membership->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();
    DB::table('chat_participants')->where('id', $membership->id)->update(['left_at' => now()]);
    $before = $room->sendMessage('Before leaving', ['created_at' => now()->subMinute()]);
    $atLeave = $room->sendMessage('At leaving');
    $room->sendMessage('After leaving', ['created_at' => now()->addSecond()]);
    $source = $owner->messages();

    $relation = new ParticipantMessages(
        $source->getRelated()->newQuery(),
        $owner,
        [new ChatParticipantTestCustomLeaveMembership],
        $source->getForeignKeys(),
        $source->getLocalKeys(),
    );

    expect($relation->withTrashed('chat_participants.left_at')->orderBy('chat_messages.id')->pluck('chat_messages.id')->all())
        ->toBe([$before->id, $atLeave->id]);
});

it('keeps membership history defaults and message relations on the participant connection', function (bool $tenantHistory) {
    Event::fake([MessageCreated::class, ParticipantCreated::class, ParticipantDeleted::class]);
    $this->freezeSecond();
    config(['chat.can_see_messages_before_joined' => false]);
    $defaultRoom = Chatroom::create(['can_see_messages_before_joined' => ! $tenantHistory]);
    ChatParticipant::create(['chatroom_id' => $defaultRoom->id, 'reference_id' => 'default-reader']);
    $defaultRoom->sendMessage('Default database message');
    config(['database.connections.history_tenant' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => true,
    ]]);
    $defaultConnection = DB::getDefaultConnection();

    try {
        DB::setDefaultConnection('history_tenant');
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (glob(__DIR__.'/../database/migrations/*.stub') as $migrationFile) {
                (require $migrationFile)->up();
            }
            $room = Chatroom::on('history_tenant')->create(['can_see_messages_before_joined' => $tenantHistory]);
            $room->sendMessage('Tenant database message');
        } finally {
            DB::setDefaultConnection($defaultConnection);
            Schema::clearResolvedInstance('db.schema');
        }

        $membership = ChatParticipant::on('history_tenant')->create(['chatroom_id' => $room->id, 'reference_id' => 'tenant-reader']);
        $explicit = ChatParticipant::on('history_tenant')->create([
            'chatroom_id' => $room->id,
            'reference_id' => 'explicit-reader',
            'can_see_messages_before_joined' => ! $tenantHistory,
        ]);

        expect($membership->fresh()->can_see_messages_before_joined)->toBe($tenantHistory)
            ->and($explicit->fresh()->can_see_messages_before_joined)->toBe(! $tenantHistory)
            ->and($membership->messages()->get()->pluck('message')->all())->toBe(['Tenant database message']);
        $loaded = ChatParticipant::on('history_tenant')->with('messages')->withCount('messages')->findOrFail($membership->id);
        expect($loaded->messages->pluck('message')->all())->toBe(['Tenant database message'])
            ->and($loaded->messages_count)->toBe(1)
            ->and($loaded->messages->first()->getConnectionName())->toBe('history_tenant')
            ->and(ChatMessage::query()->pluck('message')->all())->toBe(['Default database message']);
    } finally {
        DB::purge('history_tenant');
    }
})->with([false, true]);

it('uses message relation aliases for history constraints in self-relation queries', function (bool $includeHistory) {
    Event::fake([MessageCreated::class, ParticipantCreated::class, ParticipantDeleted::class]);
    $this->freezeSecond();
    $room = Chatroom::create(['can_see_messages_before_joined' => $includeHistory]);
    $owner = ChatParticipantTestMessageOwner::create([
        'chatroom_id' => $room->id,
        'message' => 'Owning record',
        'created_at' => now()->subMinutes(3),
    ]);
    $membership = $room->addParticipant($owner);
    $membership->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    $old = $room->sendMessage('Earlier history', ['created_at' => now()->subMinutes(2)]);
    $visible = $room->sendMessage('Visible message');
    $expectedIds = $includeHistory ? [$owner->id, $old->id, $visible->id] : [$visible->id];

    expect($owner->messages()->orderBy('chat_messages.id')->pluck('chat_messages.id')->all())->toBe($expectedIds);
    $loaded = ChatParticipantTestMessageOwner::with('messages')->withCount('messages')->findOrFail($owner->id);
    expect($loaded->messages->modelKeys())->toBe($expectedIds)
        ->and($loaded->messages_count)->toBe(count($expectedIds))
        ->and(ChatParticipantTestMessageOwner::whereKey($owner->id)->whereHas('messages')->exists())->toBeTrue();

    $connection = DB::connection();
    $prefix = $connection->getTablePrefix();
    try {
        $connection->setTablePrefix('visibility_');
        expect($owner->messages()->toSql())->toContain('visibility_chat_messages', 'visibility_chat_participants');
    } finally {
        $connection->setTablePrefix($prefix);
    }
})->with([false, true]);

it('reuses the room history default when adding multiple memberships', function (string $method, bool $default) {
    Event::fake([ParticipantCreated::class, ParticipantDeleted::class]);
    $room = Chatroom::create(['can_see_messages_before_joined' => $default]);
    $owners = [ChatParticipantTestOwner::create(), ChatParticipantTestOwner::create(), ChatParticipantTestOwner::create()];
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $room->{$method}($owners);
        $roomLookups = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => preg_match('/from ["`]?chatrooms["`]?/i', $query['query']) === 1,
        );
        expect($roomLookups)->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }

    expect($room->participants()->pluck('can_see_messages_before_joined')->all())->toBe([$default, $default, $default]);
})->with(['add' => ['addParticipants'], 'sync' => ['syncParticipants']])->with([false, true]);

it('avoids history default lookups when creation already has a room', function (string $path, bool $default) {
    Event::fake([ParticipantCreated::class, ParticipantDeleted::class]);
    config(['chat.can_see_messages_before_joined' => ! $default]);
    $room = Chatroom::create(['can_see_messages_before_joined' => $default]);
    $owner = ChatParticipantTestOwner::create();
    $recipients = [ChatParticipantTestOwner::create(), ChatParticipantTestOwner::create()];
    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        if ($path === 'relation') {
            $room->participants()->createMany([
                ['reference_id' => 'first'],
                ['reference_id' => 'second'],
                ['reference_id' => 'explicit', 'can_see_messages_before_joined' => ! $default],
            ]);
        } elseif ($path === 'personal') {
            $room = $owner->getOrCreatePersonalChatroom(['can_see_messages_before_joined' => $default]);
        } else {
            $message = $owner->sendMessageTo(
                $recipients,
                'Hello',
                forceNewChannel: true,
                newChannelConfiguration: ['can_see_messages_before_joined' => $default],
                asParticipantConfiguration: $path === 'message override' ? ['can_see_messages_before_joined' => ! $default] : [],
            );
            $room = $message->chatroom;
        }
        $lookups = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => preg_match('/select ["`]?can_see_messages_before_joined["`]? from/i', $query['query']) === 1,
        );
        expect($lookups)->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }

    $expected = match ($path) {
        'relation' => [$default, $default, ! $default],
        'personal' => [$default],
        'message override' => [! $default, $default, $default],
        default => [$default, $default, $default],
    };
    expect($room->participants()->orderBy('id')->pluck('can_see_messages_before_joined')->all())->toBe($expected);
})->with(['relation', 'personal', 'message', 'message override'])->with([false, true]);

it('does not default existing membership settings when saving a partially loaded model', function () {
    Event::fake([ParticipantCreated::class]);
    $room = Chatroom::create(['can_see_messages_before_joined' => false]);
    $membership = $room->participants()->create(['reference_id' => 'reader', 'can_see_messages_before_joined' => true]);
    $partial = ChatParticipant::select(['id', 'chatroom_id', 'reference_id'])->findOrFail($membership->id);

    $room->participants()->save($partial);

    expect($membership->fresh()->can_see_messages_before_joined)->toBeTrue();
});

it('copies the persisted room history default only for new memberships', function (bool $default) {
    $room = Chatroom::create(['can_see_messages_before_joined' => $default]);
    $owner = ChatParticipantTestOwner::create();
    $membership = $room->addParticipant($owner);
    $explicit = ChatParticipant::create([
        'chatroom_id' => $room->id,
        'reference_id' => 'explicit',
        'can_see_messages_before_joined' => ! $default,
    ]);
    $originalJoinedAt = $membership->created_at;

    $room->setCanSeeMessagesBeforeJoined(! $default);
    $membership->delete();
    $restored = $room->addParticipant($owner);
    $room->syncParticipants([$owner]);

    expect($restored->fresh()->can_see_messages_before_joined)->toBe($default)
        ->and($restored->fresh()->created_at->equalTo($originalJoinedAt))->toBeTrue()
        ->and($explicit->fresh()->can_see_messages_before_joined)->toBe(! $default);
})->with([false, true]);

it('saves membership history independently without changing other attributes', function (bool $value) {
    $room = Chatroom::create();
    $membership = ChatParticipant::create([
        'chatroom_id' => $room->id,
        'reference_id' => 'reader',
        'role' => 'admin',
        'metadata' => ['custom' => 'keep'],
        'read_at' => now()->subMinute(),
    ]);
    $original = $membership->getAttributes();
    $membership->delete();
    $deletedAt = $membership->deleted_at;

    $membership->setCanSeeMessagesBeforeJoined($value);
    $fresh = $membership->fresh();

    expect($fresh->can_see_messages_before_joined)->toBe($value)
        ->and($fresh->metadata)->toBe(['custom' => 'keep'])
        ->and($fresh->role)->toBe('admin')
        ->and($fresh->getRawOriginal('created_at'))->toBe($original['created_at'])
        ->and($fresh->getRawOriginal('read_at'))->toBe($original['read_at'])
        ->and($fresh->deleted_at->equalTo($deletedAt))->toBeTrue();
})->with([false, true]);

it('rejects direct writes through membership messages without persisting anything', function (string $method) {
    Event::fake([MessageCreated::class, ParticipantCreated::class]);
    $room = Chatroom::create();
    $membership = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'reader']);
    $attributes = ['chatroom_id' => $room->id, 'sender_id' => $membership->id, 'message' => 'Do not persist'];
    $message = new ChatMessage($attributes);
    $argument = match ($method) {
        'create' => $attributes,
        'save' => $message,
        'saveMany' => [$message, new ChatMessage($attributes)],
    };

    expect(fn () => $membership->messages()->{$method}($argument))->toThrow(LogicException::class, 'read-only');

    expect(ChatMessage::count())->toBe(0)->and($message->exists)->toBeFalse();
    Event::assertNotDispatched(MessageCreated::class);
})->with(['create', 'save', 'saveMany']);

it('filters membership messages consistently when lazy and eager loaded', function () {
    Event::fake([ParticipantCreated::class, ParticipantDeleted::class]);
    $this->freezeSecond();
    $room = Chatroom::create(['can_see_messages_before_joined' => false]);
    $restricted = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'restricted']);
    $allowed = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'allowed', 'can_see_messages_before_joined' => true]);
    foreach ([$restricted, $allowed] as $membership) {
        $membership->forceFill(['created_at' => now()->subMinute(), 'deleted_at' => now()])->saveQuietly();
    }
    $old = $room->sendMessage('Before joining');
    $old->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();
    $during = $room->sendMessage('At leaving');
    $after = $room->sendMessage('After leaving');
    $after->forceFill(['created_at' => now()->addSecond()])->saveQuietly();

    expect($restricted->messages()->orderBy('chat_messages.id')->pluck('chat_messages.id')->all())->toBe([$during->id])
        ->and($allowed->messages()->orderBy('chat_messages.id')->pluck('chat_messages.id')->all())->toBe([$old->id, $during->id]);
    $memberships = ChatParticipant::withTrashed()->with('messages')->get()->keyBy('id');
    expect($memberships[$restricted->id]->messages->modelKeys())->toBe([$during->id])
        ->and($memberships[$allowed->id]->messages->modelKeys())->toBe([$old->id, $during->id]);
});

it('supports membership message existence and count queries without alias collisions', function () {
    Event::fake([ParticipantCreated::class, ParticipantDeleted::class]);
    $this->freezeSecond();
    $room = Chatroom::create(['can_see_messages_before_joined' => false]);
    $restricted = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'restricted']);
    $allowed = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'allowed', 'can_see_messages_before_joined' => true]);
    $emptyRoom = Chatroom::create(['name' => 'Empty room']);
    $empty = ChatParticipant::create(['chatroom_id' => $emptyRoom->id, 'reference_id' => 'empty', 'can_see_messages_before_joined' => true]);
    foreach ([$restricted, $allowed] as $membership) {
        $membership->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    }
    $old = $room->sendMessage('Earlier history', ['created_at' => now()->subMinutes(2)]);
    $atLeave = $room->sendMessage('At leaving');

    $memberships = ChatParticipant::withCount('messages')->get()->keyBy('id');
    expect($memberships[$restricted->id]->messages_count)->toBe(1)
        ->and($memberships[$allowed->id]->messages_count)->toBe(2)
        ->and($memberships[$empty->id]->messages_count)->toBe(0)
        ->and(ChatParticipant::has('messages')->orderBy('id')->pluck('id')->all())->toBe([$restricted->id, $allowed->id])
        ->and(ChatParticipant::doesntHave('messages')->pluck('id')->all())->toBe([$empty->id])
        ->and(ChatParticipant::has('messages', '>=', 2)->pluck('id')->all())->toBe([$allowed->id])
        ->and(ChatParticipant::whereHas('messages', fn (Builder $query) => $query->whereKey($old->id))->pluck('id')->all())->toBe([$allowed->id]);

    foreach ([$restricted, $allowed] as $membership) {
        $membership->forceFill(['deleted_at' => now()])->saveQuietly();
    }
    $room->sendMessage('After leaving', ['created_at' => now()->addSecond()]);
    $memberships = ChatParticipant::withTrashed()->withCount('messages')->get()->keyBy('id');
    expect($memberships[$restricted->id]->messages_count)->toBe(1)
        ->and($memberships[$allowed->id]->messages_count)->toBe(2)
        ->and($memberships[$empty->id]->messages_count)->toBe(0)
        ->and(ChatParticipant::withTrashed()->whereHas('messages', fn (Builder $query) => $query->whereKey($atLeave->id))->count())->toBe(2);
});

it('limits eager membership messages separately for each parent', function () {
    if (! method_exists(Illuminate\Database\Query\Builder::class, 'groupLimit')) {
        $this->markTestSkipped('Native per-parent eager limits require Laravel 11 or later.');
    }
    Event::fake([MessageCreated::class, ParticipantCreated::class, ParticipantDeleted::class]);
    $this->freezeSecond();
    $room = Chatroom::create(['can_see_messages_before_joined' => false]);
    $earlierReader = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'earlier']);
    $laterReader = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'later']);
    $earlierReader->forceFill(['created_at' => now()->subMinutes(3), 'deleted_at' => now()->subMinute()])->saveQuietly();
    $laterReader->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    $room->sendMessage('Earlier message', ['created_at' => now()->subMinutes(2)]);
    $earlierLatest = $room->sendMessage('Earlier latest', ['created_at' => now()->subMinute()]);
    $laterLatest = $room->sendMessage('Later latest');

    $memberships = ChatParticipant::withTrashed()->with([
        'messages' => fn ($relation) => $relation->orderByDesc('chat_messages.created_at')->orderByDesc('chat_messages.id')->limit(1),
    ])->get()->keyBy('id');

    expect($memberships[$earlierReader->id]->messages->modelKeys())->toBe([$earlierLatest->id])
        ->and($memberships[$laterReader->id]->messages->modelKeys())->toBe([$laterLatest->id]);
});

it('limits model message relations to the leave time when including departed memberships', function (bool $includeHistory) {
    Event::fake([ParticipantCreated::class, ParticipantDeleted::class]);
    $this->freezeSecond();
    $room = Chatroom::create(['can_see_messages_before_joined' => $includeHistory]);
    $owner = ChatParticipantTestOwner::create();
    $membership = $room->addParticipant($owner);
    $joinedAt = now()->subMinute();
    $membership->forceFill(['created_at' => $joinedAt, 'deleted_at' => now()])->saveQuietly();
    $before = $room->sendMessage('Before joining', ['created_at' => $joinedAt->copy()->subSecond()]);
    $atJoin = $room->sendMessage('At joining', ['created_at' => $joinedAt]);
    $atLeave = $room->sendMessage('At leaving');
    $room->sendMessage('After leaving', ['created_at' => now()->addSecond()]);
    $expectedIds = $includeHistory ? [$before->id, $atJoin->id, $atLeave->id] : [$atJoin->id, $atLeave->id];

    expect($owner->messages()->count())->toBe(0)
        ->and($owner->messages()->withTrashed('chat_participants.deleted_at')
            ->orderBy('chat_messages.id')->pluck('chat_messages.id')->all())->toBe($expectedIds);

    $owner->load(['messages' => fn ($relation) => $relation->withTrashed('chat_participants.deleted_at')->orderBy('chat_messages.id')]);
    expect($owner->messages->modelKeys())->toBe($expectedIds);

    $membership->restore();
    expect($owner->messages()->count())->toBe($includeHistory ? 4 : 3);
})->with([false, true]);

it('applies viewer history settings and overrides to loaded parents and replies', function () {
    Event::fake([ParticipantCreated::class, ParticipantDeleted::class]);
    $this->freezeSecond();
    $room = Chatroom::create(['can_see_messages_before_joined' => false]);
    $owner = ChatParticipantTestOwner::create();
    $membership = $room->addParticipant($owner);
    $membership->forceFill(['created_at' => now()->subMinute(), 'deleted_at' => now()])->saveQuietly();
    $old = $room->sendMessage('Hidden parent');
    $old->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();
    $visible = $room->sendMessage('Visible reply', ['reply_to_id' => $old->id]);
    $future = $room->sendMessage('Hidden reply', ['reply_to_id' => $visible->id]);
    $future->forceFill(['created_at' => now()->addSecond()])->saveQuietly();

    $message = $owner->getMessages(withReplies: true, withParentMessage: true)->sole();
    expect($message->id)->toBe($visible->id)
        ->and($message->parentMessage)->toBeNull()
        ->and($message->replies)->toBeEmpty();

    $membership->setCanSeeMessagesBeforeJoined(true);
    $message = $owner->getMessages(withParentMessage: true)->firstWhere('id', $visible->id);
    expect($message->parentMessage->id)->toBe($old->id);
    $message = $owner->getMessages(includeBeforeJoined: false, withParentMessage: true)->sole();
    expect($message->parentMessage)->toBeNull();
});

it('sends a message as the participant to its chatroom', function () {
    $room = Chatroom::create(['name' => 'Participant messages']);
    $participant = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'member:sender',
    ]);

    $message = $participant->sendMessage('Hello from the participant');

    expect($message)->toBeInstanceOf(ChatMessage::class)
        ->and($message->chatroom_id)->toBe($room->getKey())
        ->and($message->sender_id)->toBe($participant->getKey())
        ->and($message->message)->toBe('Hello from the participant');
});

it('stores generic mention aliases and exposes no AI policy for human participants', function () {
    $room = Chatroom::create(['name' => 'Mentions', 'metadata' => ['reference_id' => 'keep-me']]);
    $first = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'agent:first', 'display_name' => 'Guide AI', 'metadata' => ['custom' => 'preserved']]);
    $second = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'agent:second']);

    $first->setMentionAliases(['GSAI']);
    $second->setMentionAliases(['other']);

    $first = $first->fresh();
    $first->preventsLazyLoading = true;
    expect($first->mentionAliases())->toBe(['gsai'])
        ->and($first->metadata)->toBe(['custom' => 'preserved', 'mention_aliases' => ['gsai']])
        ->and($room->fresh()->metadata)->toBe(['reference_id' => 'keep-me'])
        ->and($second->fresh()->metadata)->toBe(['mention_aliases' => ['other']]);
    $payload = (new ChatParticipantResource($first))->resolve();
    expect($payload['mention_token'])->toBe('<@'.$first->id.'>')
        ->and($payload['mention_aliases'])->toBe(['gsai'])
        ->and($payload)->not->toHaveKey('metadata')
        ->and($payload)->not->toHaveKey('ai_response_mode');
});

it('rejects ambiguous aliases without changing another participant settings', function () {
    $room = Chatroom::create(['name' => 'Mentions']);
    $first = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'agent:first']);
    $second = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'agent:second']);
    $first->setMentionAliases(['gsai']);

    expect(fn () => $second->setMentionAliases(['GSAI']))->toThrow(ValidationException::class, 'unique within the chatroom');

    expect($first->fresh()->mentionAliases())->toBe(['gsai'])
        ->and($second->fresh()->mentionAliases())->toBe([]);
});

it('rejects invalid alias values before changing stored preferences', function (mixed $alias) {
    $room = Chatroom::create(['name' => 'Mentions']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'agent:first']);

    expect(fn () => $participant->setMentionAliases([$alias]))->toThrow(ValidationException::class);

    expect($participant->fresh()->mentionAliases())->toBe([])
        ->and((new ChatParticipantResource($participant->fresh()))->resolve())->not->toHaveKey('ai_response_mode');
})->with([
    'number' => [123],
    'array' => [['not-a-string']],
    'spaces' => ['contains spaces'],
    'at-sign prefix' => ['@gsai'],
]);

it('stores AI response modes on each participant without changing room metadata or aliases', function () {
    $room = Chatroom::create(['metadata' => ['custom' => 'room']]);
    $otherRoom = Chatroom::create([]);
    $agent = ChatParticipant::create([
        'chatroom_id' => $room->id,
        'reference_id' => 'ai-agent:SupportAgent',
        'metadata' => ['custom' => 'participant'],
    ]);
    $otherAgent = ChatParticipant::create(['chatroom_id' => $otherRoom->id, 'reference_id' => $agent->reference_id]);
    $agent->setMentionAliases(['support']);

    $agent->setAiResponseMode('mentions');

    expect($agent->fresh()->aiResponseMode())->toBe('mentions')
        ->and($agent->fresh()->metadata)->toBe([
            'custom' => 'participant',
            'mention_aliases' => ['support'],
            'ai_response_mode' => 'mentions',
        ])
        ->and($otherAgent->fresh()->aiResponseMode())->toBe('all')
        ->and($otherAgent->fresh()->metadata)->toBeNull()
        ->and($room->fresh()->metadata)->toBe(['custom' => 'room']);

    $agent->setMentionAliases([]);

    expect($agent->fresh()->mentionAliases())->toBe([])
        ->and($agent->fresh()->aiResponseMode())->toBe('mentions');
});

it('rejects invalid AI modes without changing participant metadata', function () {
    $room = Chatroom::create([]);
    $agent = ChatParticipant::create([
        'chatroom_id' => $room->id,
        'reference_id' => 'ai-agent:SupportAgent',
        'metadata' => ['ai_response_mode' => 'mentions'],
    ]);

    expect(fn () => $agent->setAiResponseMode('invalid'))->toThrow(ValidationException::class);

    expect($agent->fresh()->metadata)->toBe(['ai_response_mode' => 'mentions']);
});

it('does not configure human or removed participants as AI responders', function (bool $removed) {
    $room = Chatroom::create([]);
    $participant = ChatParticipant::create([
        'chatroom_id' => $room->id,
        'reference_id' => $removed ? 'ai-agent:SupportAgent' : 'human',
    ]);
    if ($removed) {
        $participant->delete();
    }

    expect(fn () => $participant->setAiResponseMode('all'))->toThrow(ValidationException::class);

    expect($participant->aiResponseMode())->toBe('off')
        ->and($participant->fresh()->metadata)->toBeNull()
        ->and($participant->fresh()->trashed())->toBe($removed);
})->with(['human' => false, 'removed agent' => true]);

it('disables automatic replies for invalid stored AI modes', function () {
    $room = Chatroom::create([]);
    $agent = ChatParticipant::create([
        'chatroom_id' => $room->id,
        'reference_id' => 'ai-agent:SupportAgent',
        'metadata' => ['ai_response_mode' => 'invalid'],
    ]);

    expect($agent->fresh()->aiResponseMode())->toBe('off');
});

it('creates and reuses a personal chatroom for its model participant', function () {
    $owner = ChatParticipantTestOwner::create(['name' => 'Personal owner']);

    $room = $owner->getOrCreatePersonalChatroom(['name' => 'Personal room']);
    $membership = $room->participant($owner);
    $reused = $owner->getOrCreatePersonalChatroom(['name' => 'Ignored name']);

    expect($room->name)->toBe('Personal room')
        ->and($membership)->toBeInstanceOf(ChatParticipant::class)
        ->and($membership->role)->toBe('admin')
        ->and($owner->isParticipantIn($room))->toBeTrue()
        ->and($owner->asParticipantIn($room)?->getKey())->toBe($membership->getKey())
        ->and($owner->chatRooms()->pluck('chatrooms.id')->all())->toBe([$room->getKey()])
        ->and($owner->chatRoomsBuilder()->pluck('id')->all())->toBe([$room->getKey()])
        ->and($reused->getKey())->toBe($room->getKey());

    $membership->delete();

    expect($owner->asParticipantIn($room))->toBeNull()
        ->and($owner->asParticipantIn($room, true)?->getKey())->toBe($membership->getKey())
        ->and($owner->isOrWasParticipantIn($room))->toBeTrue();
});

it('reuses a direct chatroom and creates a separate room when forced', function () {
    $sender = ChatParticipantTestOwner::create(['name' => 'Sender']);
    $recipient = ChatParticipantTestOwner::create(['name' => 'Recipient']);

    $first = $sender->sendMessageTo($recipient, 'First message', newChannelConfiguration: ['name' => 'Direct']);
    $second = $sender->sendMessageTo($recipient, 'Second message');
    $separate = $sender->sendMessageTo($recipient, 'Separate message', forceNewChannel: true, newChannelConfiguration: ['name' => 'Another direct']);

    expect($first->chatroom_id)->toBe($second->chatroom_id)
        ->and($separate->chatroom_id)->not->toBe($first->chatroom_id)
        ->and($first->chatroom->name)->toBe('Direct')
        ->and($separate->chatroom->name)->toBe('Another direct')
        ->and($first->sender->participant_id)->toBe($sender->getKey())
        ->and($first->sender->participant_type)->toBe($sender->getMorphClass())
        ->and($recipient->isParticipantIn($first->chatroom))->toBeTrue()
        ->and($sender->sentMessages()->pluck('chat_messages.id')->all())->toBe([
            $first->getKey(), $second->getKey(), $separate->getKey(),
        ]);
});

it('creates join messages before the first message without waiting for queued listeners', function () {
    $this->freezeTime();
    Queue::fake([CallQueuedListener::class]);
    $sender = ChatParticipantTestOwner::create(['name' => 'Sender']);
    $recipient = ChatParticipantTestOwner::create(['name' => 'Recipient']);

    $message = $sender->sendMessageTo($recipient, 'First message');

    expect($recipient->getMessages()->pluck('message')->all())->toBe([
        'First message',
        'Sender and Recipient joined the chat.',
    ]);
    expect($message->chatroom->messages()->orderBy('id')->pluck('message')->all())->toBe([
        'Sender and Recipient joined the chat.',
        'First message',
    ]);
    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $listener): bool => $listener->class === SendParticipantCreatedNotification::class);
});

it('uses the fifth positional argument for sender participant configuration', function () {
    $sender = ChatParticipantTestOwner::create(['name' => 'Support']);
    $recipient = ChatParticipantTestOwner::create(['name' => 'Recipient']);

    $message = $sender->sendMessageTo(
        $recipient,
        'Welcome',
        true,
        ['name' => 'Support room'],
        ['display_name' => 'Support Team'],
    );

    expect($message->chatroom->name)->toBe('Support room')
        ->and($sender->asParticipantIn($message->chatroom)?->display_name)->toBe('Support Team');
});

it('paginates visible messages and loads replies for model participants', function () {
    $this->freezeTime();
    config(['chat.create_system_messages' => false]);
    $room = Chatroom::create(['name' => 'Visible messages']);
    $reader = ChatParticipantTestOwner::create();
    $sender = ChatParticipantTestOwner::create();
    Event::fake([ParticipantCreated::class]);
    $room->addParticipant($reader);
    $room->addParticipant($sender);

    $first = $sender->sendMessageTo($room, 'First');
    $second = $sender->sendMessageTo($room, 'Second');
    $reply = $reader->replyTo($second, 'Reply');
    $visible = $reader->getMessages(withReplies: true, withParentMessage: true);

    expect($reader->visibleMessages(limit: 2, offset: 1)->pluck('id')->all())
        ->toBe([$second->getKey(), $first->getKey()])
        ->and($visible->modelKeys())->toBe([$reply->getKey(), $second->getKey(), $first->getKey()])
        ->and($visible->find($second->getKey())->replies->modelKeys())->toBe([$reply->getKey()])
        ->and($visible->find($reply->getKey())->parentMessage->getKey())->toBe($second->getKey())
        ->and($reader->getMessagesSentTo($room)->modelKeys())->toBe([$reply->getKey()]);
});

it('tracks unread messages and never moves the read marker backwards', function () {
    $this->freezeTime();
    config(['chat.create_system_messages' => false]);
    $room = Chatroom::create(['name' => 'Read state']);
    $reader = ChatParticipantTestOwner::create();
    $sender = ChatParticipantTestOwner::create();
    Event::fake([ParticipantCreated::class]);
    $room->addParticipant($reader);
    $room->addParticipant($sender);
    $this->travel(1)->seconds();

    $first = $sender->sendMessageTo($room, 'First unread');
    $this->travel(1)->seconds();
    $reader->sendMessageTo($room, 'Own message');
    $this->travel(1)->seconds();
    $later = $sender->sendMessageTo($room, 'Later unread');

    expect(ChatParticipantTestOwner::query()->whereKey($reader->getKey())->whereHasUnreadMessages()->exists())->toBeTrue()
        ->and(ChatParticipantTestOwner::query()->whereKey($reader->getKey())->whereHasUnreadMessagesToday()->exists())->toBeTrue();

    $reader->loadUnreadMessagesCount();
    expect($reader->unread_messages_count)->toBe(1);

    $reader->markRead($later);
    $laterReadAt = $reader->asParticipantIn($room)->fresh()->read_at;
    $reader->markReadUntil($room, new DateTimeImmutable($first->created_at->toDateTimeString()));

    expect($reader->asParticipantIn($room)->fresh()->read_at->equalTo($laterReadAt))->toBeTrue()
        ->and(ChatParticipantTestOwner::query()->whereKey($reader->getKey())->whereHasUnreadMessages()->exists())->toBeFalse();

    $this->travel(1)->seconds();
    $room->sendMessage('System notice');

    expect(ChatParticipantTestOwner::query()->whereKey($reader->getKey())->whereHasUnreadMessages()->exists())->toBeFalse()
        ->and(ChatParticipantTestOwner::query()->whereKey($reader->getKey())->whereHasUnreadMessages(includeSystemMessages: true)->exists())->toBeTrue();

    $reader->loadUnreadMessagesCount(includeSystemMessages: true);
    expect($reader->unread_messages_count)->toBe(1);
});

it('uses membership history for model message relations and unread owner queries', function (bool $includeHistory) {
    Event::fake([ParticipantCreated::class]);
    $room = Chatroom::create(['can_see_messages_before_joined' => ! $includeHistory]);
    $reader = ChatParticipantTestOwner::create();
    $sender = ChatParticipantTestOwner::create();
    $membership = $room->addParticipant($reader);
    $room->addParticipant($sender);
    $membership->setCanSeeMessagesBeforeJoined($includeHistory);
    $membership->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    $old = $sender->sendMessageTo($room, 'Earlier unread message');
    $old->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();

    expect(ChatParticipantTestOwner::whereKey($reader->id)->whereHasUnreadMessages()->exists())->toBe($includeHistory)
        ->and($reader->messages()->pluck('chat_messages.id')->all())->toBe($includeHistory ? [$old->id] : []);
    $reader->loadUnreadMessagesCount();
    expect($reader->unread_messages_count)->toBe($includeHistory ? 1 : 0);
})->with([false, true]);
