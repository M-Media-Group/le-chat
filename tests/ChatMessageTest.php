<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Http\Resources\MessageResource;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;
use Mmedia\LeChat\Traits\IsChatParticipant;

/** @implements ChatParticipantInterface<ChatMessageTestParticipant> */
class ChatMessageTestParticipant extends Model implements ChatParticipantInterface
{
    use IsChatParticipant;

    protected $table = 'chat_message_test_participants';

    protected $guarded = [];
}

beforeEach(function () {
    ChatMessage::flushMessageMode();
    Event::fake([MessageCreated::class, MessageUpdated::class, ParticipantCreated::class]);
    config(['chat.features' => []]);
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
});

it('resolves a sender and its direct replies', function () {
    $room = Chatroom::create(['name' => 'Messages']);
    $sender = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'sender',
    ]);
    $original = $room->sendMessageAs($sender, 'Original message');
    $reply = $room->sendMessageAs($sender, 'Reply', ['reply_to_id' => $original->getKey()]);

    expect($original->sender->getKey())->toBe($sender->getKey())
        ->and($original->replies()->pluck('id')->all())->toBe([$reply->getKey()])
        ->and($reply->parentMessage->getKey())->toBe($original->getKey());
});

it('delegates read marking and reply creation to the participant', function () {
    $message = new ChatMessage;
    $participant = Mockery::mock(ChatParticipantInterface::class);
    $reply = new ChatMessage;

    $participant->shouldReceive('markRead')->once()->with($message)->andReturnTrue();
    $participant->shouldReceive('replyTo')->once()->with($message, 'Reply', [])->andReturn($reply);

    expect($message->markAsReadBy($participant))->toBeTrue()
        ->and($message->replyAs($participant, 'Reply'))->toBe($reply);
});

it('filters messages by sender', function () {
    $room = Chatroom::create(['name' => 'Messages']);
    $firstSender = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'first-sender',
    ]);
    $secondSender = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'second-sender',
    ]);
    $firstMessage = $room->sendMessageAs($firstSender, 'First');
    $room->sendMessageAs($secondSender, 'Second');

    expect(ChatMessage::query()->sentBy($firstSender)->pluck('id')->all())->toBe([$firstMessage->getKey()]);
});

it('limits visible messages to the participant membership period', function () {
    $room = Chatroom::create(['name' => 'Membership period']);
    $joinedAt = now()->subMinutes(2);
    $leftAt = now()->subSeconds(30);
    $participant = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'reader',
    ]);
    $participant->forceFill(['created_at' => $joinedAt])->saveQuietly();
    $beforeJoin = ChatMessage::create([
        'chatroom_id' => $room->getKey(),
        'message' => 'Before join',
    ]);
    $beforeJoin->forceFill(['created_at' => $joinedAt->copy()->subMinute()])->saveQuietly();
    $duringMembership = ChatMessage::create([
        'chatroom_id' => $room->getKey(),
        'message' => 'During membership',
    ]);
    $duringMembership->forceFill(['created_at' => $joinedAt->copy()->addMinute()])->saveQuietly();
    $afterLeaving = ChatMessage::create([
        'chatroom_id' => $room->getKey(),
        'message' => 'After leaving',
    ]);
    $afterLeaving->forceFill(['created_at' => now()])->saveQuietly();
    $participant->forceFill(['deleted_at' => $leftAt])->saveQuietly();

    expect(ChatMessage::query()->visibleTo($participant)->pluck('id')->all())
        ->toBe([$duringMembership->getKey()])
        ->and(ChatMessage::query()->visibleTo($participant, true, true)->pluck('id')->all())
        ->toBe([$beforeJoin->getKey(), $duringMembership->getKey()])
        ->and(ChatMessage::query()->visibleTo($participant, false)->pluck('id')->all())
        ->toBe([])
        ->and($afterLeaving->exists)->toBeTrue();
});

it('returns unread messages after the participant read timestamp', function () {
    $room = Chatroom::create(['name' => 'Unread messages']);
    $reader = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'reference_id' => 'reader',
    ]);
    $beforeRead = ChatMessage::create([
        'chatroom_id' => $room->getKey(),
        'message' => 'Already read',
    ]);
    $beforeRead->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    $afterRead = ChatMessage::create([
        'chatroom_id' => $room->getKey(),
        'message' => 'Still unread',
    ]);
    $afterRead->forceFill(['created_at' => now()])->saveQuietly();
    $reader->forceFill(['read_at' => now()->subSeconds(30)])->saveQuietly();
    $reader->refresh();

    expect(ChatMessage::query()->unreadBy($reader)->pluck('id')->all())->toBe([$afterRead->getKey()])
        ->and($beforeRead->exists)->toBeTrue();
});

it('supports model participants in visibility and unread scopes', function () {
    Schema::create('chat_message_test_participants', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });

    $room = Chatroom::create(['name' => 'Model participant scopes']);
    $participantModel = ChatMessageTestParticipant::create();
    $membership = $room->addParticipant($participantModel);
    $joinedAt = now()->subMinutes(3);
    $leftAt = now()->subMinute();
    $membership->forceFill(['created_at' => $joinedAt, 'deleted_at' => $leftAt])->saveQuietly();

    $beforeJoin = ChatMessage::create([
        'chatroom_id' => $room->getKey(),
        'message' => 'Before join',
    ]);
    $beforeJoin->forceFill(['created_at' => $joinedAt->copy()->subMinute()])->saveQuietly();
    $duringMembership = ChatMessage::create([
        'chatroom_id' => $room->getKey(),
        'message' => 'During membership',
    ]);
    $duringMembership->forceFill(['created_at' => $joinedAt->copy()->addMinute()])->saveQuietly();
    $afterLeaving = ChatMessage::create([
        'chatroom_id' => $room->getKey(),
        'message' => 'After leaving',
    ]);
    $afterLeaving->forceFill(['created_at' => now()])->saveQuietly();
    $membership->forceFill(['read_at' => $duringMembership->created_at])->saveQuietly();

    expect(ChatMessage::query()->visibleTo($participantModel)->pluck('id')->all())
        ->toBe([$duringMembership->getKey()])
        ->and(ChatMessage::query()->visibleTo($participantModel, true, true)->pluck('id')->all())
        ->toBe([$beforeJoin->getKey(), $duringMembership->getKey()])
        ->and(ChatMessage::query()->visibleTo($participantModel, false)->pluck('id')->all())
        ->toBe([])
        ->and(ChatMessage::query()->unreadBy($participantModel)->pluck('id')->all())
        ->toBe([$afterLeaving->getKey()]);
});

it('encrypts message text at rest and decrypts it when read', function () {
    config(['chat.features' => [Features::encryptMessagesAtRest()]]);
    $room = Chatroom::create(['name' => 'Encrypted messages']);
    $message = $room->sendMessage('Secret message');

    expect($message->getRawOriginal('message'))->not->toBe('Secret message')
        ->and($message->message)->toBe('Secret message');
});

it('throws on invalid ciphertext unless soft decryption is enabled', function () {
    config(['chat.features' => [Features::encryptMessagesAtRest()]]);
    $room = Chatroom::create(['name' => 'Encrypted messages']);
    $message = $room->sendMessage('Secret message');
    DB::table('chat_messages')->where('id', $message->getKey())->update(['message' => 'invalid ciphertext']);
    $message->refresh();

    expect(fn () => $message->message)->toThrow(DecryptException::class);

    config(['chat-options.encrypt-messages-at-rest.return_failed_decrypt' => true]);
    ChatMessage::flushMessageMode();
    $message->refresh();

    expect($message->message)->toBe('invalid ciphertext');
});

it('omits metadata from messages selected without the metadata column', function () {
    $room = Chatroom::create(['name' => 'Messages without metadata']);
    $message = $room->sendMessage('Visible text', ['metadata' => ['payload' => 'large']]);

    $selectedMessage = ChatMessage::query()->withoutMetadata()->findOrFail($message->getKey());
    $payload = MessageResource::make($selectedMessage)->resolve();

    expect($selectedMessage->message)->toBe('Visible text')
        ->and($selectedMessage->getAttributes())->not->toHaveKey('metadata')
        ->and($payload)->not->toHaveKey('metadata');
});

it('clears message content on delete and does not allow restore', function () {
    $room = Chatroom::create(['name' => 'Deleted messages']);
    $message = $room->sendMessage('Remove this text');

    $message->delete();

    expect(DB::table('chat_messages')->where('id', $message->getKey())->value('message'))->toBeNull()
        ->and($message->fresh()->message)->toBe('')
        ->and($message->deleted_at)->not->toBeNull()
        ->and(ChatMessage::query()->whereKey($message->getKey())->exists())->toBeTrue()
        ->and(ChatMessage::query()->withoutTrashed()->whereKey($message->getKey())->exists())->toBeFalse()
        ->and(fn () => $message->restore())->toThrow(BadMethodCallException::class);
});
