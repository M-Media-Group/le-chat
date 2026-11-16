<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Http\Resources\ChatParticipantResource;
use Mmedia\LeChat\Listeners\SendParticipantCreatedNotification;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;
use Mmedia\LeChat\Traits\IsChatParticipant;

/** @implements ChatParticipantInterface<ChatParticipantTestOwner> */
class ChatParticipantTestOwner extends Model implements ChatParticipantInterface
{
    use IsChatParticipant;

    protected $table = 'chat_participant_test_owners';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('chat_participant_test_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
    });
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
        'Recipient has joined the chat.',
        'Sender has joined the chat.',
    ]);
    expect($message->chatroom->messages()->orderBy('id')->pluck('message')->all())->toBe([
        'Sender has joined the chat.',
        'Recipient has joined the chat.',
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
