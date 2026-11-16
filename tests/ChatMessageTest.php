<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Events\ParticipantDeleted;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Http\Resources\MessageResource;
use Mmedia\LeChat\Models\ChatFile;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;
use Mmedia\LeChat\Notifications\NewMessage;
use Mmedia\LeChat\Traits\IsChatParticipant;
use NotificationChannels\WebPush\WebPushMessage;

/** @implements ChatParticipantInterface<ChatMessageTestParticipant> */
class ChatMessageTestParticipant extends Model implements ChatParticipantInterface
{
    use IsChatParticipant;

    protected $table = 'chat_message_test_participants';

    protected $guarded = [];
}

beforeEach(function () {
    ChatMessage::flushMessageMode();
    Event::fake([MessageCreated::class, MessageUpdated::class, ParticipantCreated::class, ParticipantDeleted::class]);
    config(['chat.features' => [Features::files()]]);
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
});

function chatMessageTestFile(ChatParticipant $owner, string $name = 'file.txt'): ChatFile
{
    $path = 'chat-files/'.uniqid('', true);
    Storage::disk('local')->put($path, 'Private notes');

    return ChatFile::create([
        'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(),
        'disk' => 'local', 'path' => $path, 'name' => $name, 'mime_type' => 'text/plain',
        'size' => 13, 'sha256' => hash('sha256', 'Private notes'), 'status' => 'ready',
        'expires_at' => now()->addDay(),
    ]);
}

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

it('validates mention IDs against active membership in this room rather than model IDs', function (string $invalidTarget) {
    $room = Chatroom::create(['name' => 'Mentions']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $otherRoom = Chatroom::create(['name' => 'Other room']);
    $target = ChatParticipant::create(['chatroom_id' => $invalidTarget === 'other_room' ? $otherRoom->id : $room->id, 'reference_id' => 'target', 'participant_id' => 999999]);
    if ($invalidTarget === 'removed') {
        $target->delete();
    }
    $id = $invalidTarget === 'model_id' ? $target->participant_id : $target->id;
    $count = $room->messages()->count();

    expect(fn () => $room->sendMessageAs($sender, 'Hello <@'.$id.'>'))
        ->toThrow(ValidationException::class, 'Mentions must reference active participants');

    expect($room->messages()->count())->toBe($count);
    Event::assertNotDispatched(MessageCreated::class);
})->with(['other_room', 'removed', 'model_id']);

it('keeps canonical mention IDs in message text and accepts repeated valid mentions', function () {
    $room = Chatroom::create(['name' => 'Mentions']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $target = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'target', 'display_name' => 'Guide']);
    $text = 'Hello '.$target->mentionToken().' and again '.$target->mentionToken();

    $message = $room->sendMessageAs($sender, $text);

    expect($message->fresh()->message)->toBe($text);
    Event::assertDispatched(MessageCreated::class, fn (MessageCreated $event): bool => $event->message->is($message));
});

it('rejects invalid mentions during edits without persisting a changed version', function () {
    $room = Chatroom::create(['name' => 'Mentions']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $message = $room->sendMessageAs($sender, 'Original');

    expect(fn () => $message->updateContent(1, ['message' => 'Hello <@999999>']))->toThrow(ValidationException::class);

    expect($message->fresh()->message)->toBe('Original')
        ->and($message->fresh()->version)->toBe(1);
    Event::assertNotDispatched(MessageUpdated::class);
});

it('validates mentions in section blocks even when the message text does not repeat them', function () {
    $room = Chatroom::create(['name' => 'Mentions']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);

    expect(fn () => $room->sendMessageAs($sender, 'Question', ['blocks' => [
        ['schema_version' => 1, 'block_id' => 'question', 'type' => 'section', 'text' => 'Hello <@999999>'],
    ]]))->toThrow(ValidationException::class, 'Mentions must reference active participants');

    Event::assertNotDispatched(MessageCreated::class);
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

it('serializes message metadata as a JSON object', function () {
    $room = Chatroom::create(['name' => 'Messages with metadata']);
    $message = $room->sendMessage('Visible text', ['metadata' => ['payload' => 'large']]);

    $payload = MessageResource::make($message)->resolve();

    expect($payload['metadata'])->toBeObject()
        ->and((array) $payload['metadata'])->toBe(['payload' => 'large']);
});

it('does not expose private AI replay metadata in message resources', function () {
    $room = Chatroom::create(['name' => 'AI messages']);
    $message = $room->sendMessage('Approval needed', ['metadata' => [
        'public' => 'visible',
        'ai' => ['steps' => [['replay_blocks' => ['private-provider-state']]]],
    ]]);

    $payload = MessageResource::make($message)->resolve();

    expect((array) $payload['metadata'])->toBe(['public' => 'visible']);
});

it('stores message blocks with derived fallback text', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);
    $blocks = [
        ['schema_version' => 1, 'type' => 'section', 'block_id' => 'review', 'text' => 'Review this request'],
        ['schema_version' => 1, 'type' => 'actions', 'block_id' => 'actions', 'elements' => [
            ['type' => 'button', 'action_id' => 'approve', 'text' => 'Approve'],
        ]],
    ];

    $message = $room->sendMessage('', ['blocks' => $blocks]);

    expect($message->fresh()->blocks)->toBe($blocks)
        ->and($message->fresh()->message)->toBe("Review this request\nApprove");
});

it('rejects an empty message block list', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);

    expect(fn () => $room->sendMessage('', ['blocks' => []]))
        ->toThrow(ValidationException::class);
    expect($room->messages()->count())->toBe(0);
});

it('rejects unknown message block types', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);

    expect(fn () => $room->sendMessage('', ['blocks' => [
        ['schema_version' => 1, 'type' => 'unknown', 'block_id' => 'invalid'],
    ]]))->toThrow(ValidationException::class);

    expect($room->messages()->count())->toBe(0);
});

it('requires text on section blocks', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);

    expect(fn () => $room->sendMessage('', ['blocks' => [
        ['schema_version' => 1, 'type' => 'section', 'block_id' => 'missing-text'],
    ]]))->toThrow(ValidationException::class);

    expect($room->messages()->count())->toBe(0);
});

it('requires elements on action blocks', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);

    expect(fn () => $room->sendMessage('', ['blocks' => [
        ['schema_version' => 1, 'type' => 'actions', 'block_id' => 'missing-elements'],
    ]]))->toThrow(ValidationException::class);

    expect($room->messages()->count())->toBe(0);
});

it('rejects duplicate block and button identifiers', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);
    $button = ['type' => 'button', 'action_id' => 'approve', 'text' => 'Approve'];

    expect(fn () => $room->sendMessage('', ['blocks' => [
        ['schema_version' => 1, 'type' => 'actions', 'block_id' => 'actions', 'elements' => [$button, $button]],
    ]]))->toThrow(ValidationException::class);
    expect(fn () => $room->sendMessage('', ['blocks' => [
        ['schema_version' => 1, 'type' => 'section', 'block_id' => 'same', 'text' => 'One'],
        ['schema_version' => 1, 'type' => 'section', 'block_id' => 'same', 'text' => 'Two'],
    ]]))->toThrow(ValidationException::class);
});

it('enforces configured message block size limits', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);
    config(['chat.blocks.max_payload_bytes' => 80]);

    expect(fn () => $room->sendMessage('', ['blocks' => [
        ['schema_version' => 1, 'type' => 'section', 'block_id' => 'long', 'text' => str_repeat('x', 100)],
    ]]))->toThrow(ValidationException::class);
    expect($room->messages()->count())->toBe(0);
});

it('erases encrypted blocks when a message is deleted', function () {
    config(['chat.features' => [Features::encryptMessagesAtRest()]]);
    $room = Chatroom::create(['name' => 'Rich messages']);
    $message = $room->sendMessage('', ['blocks' => [
        ['schema_version' => 1, 'type' => 'section', 'block_id' => 'secret', 'text' => 'Private'],
    ]]);

    $message->delete();

    $raw = DB::table('chat_messages')->where('id', $message->id)->first();
    expect($raw->message)->toBeNull()->and($raw->blocks)->toBeNull();
});

it('encrypts both message blocks and derived fallback text', function () {
    config(['chat.features' => [Features::encryptMessagesAtRest()]]);
    $room = Chatroom::create(['name' => 'Rich messages']);
    $blocks = [['schema_version' => 1, 'type' => 'section', 'block_id' => 'secret', 'text' => 'Private details']];

    $message = $room->sendMessage('', ['blocks' => $blocks]);
    $raw = DB::table('chat_messages')->where('id', $message->id)->first();

    expect($raw->message)->not->toContain('Private details')
        ->and($raw->blocks)->not->toContain('Private details')
        ->and($message->fresh()->message)->toBe('Private details')
        ->and($message->fresh()->blocks)->toBe($blocks);
});

it('migrates block messages to encryption and back without changing deleted content', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);
    $blocks = [['schema_version' => 1, 'type' => 'section', 'block_id' => 'secret', 'text' => 'Private details']];
    $message = $room->sendMessage('', ['blocks' => $blocks]);
    $deleted = $room->sendMessage('Deleted');
    $deleted->delete();

    $this->artisan('le-chat:encrypt-messages')->assertSuccessful();
    $this->artisan('le-chat:encrypt-messages')->assertSuccessful();

    $raw = DB::table('chat_messages')->where('id', $message->id)->first();
    expect($raw->message)->not->toContain('Private details')
        ->and($raw->blocks)->not->toContain('Private details')
        ->and(DB::table('chat_messages')->where('id', $deleted->id)->value('message'))->toBeNull();

    config(['chat.features' => [Features::encryptMessagesAtRest()]]);
    ChatMessage::flushMessageMode();
    expect($message->fresh()->message)->toBe('Private details')
        ->and($message->fresh()->blocks)->toBe($blocks);

    $this->artisan('le-chat:decrypt-messages')->assertSuccessful();
    config(['chat.features' => []]);
    ChatMessage::flushMessageMode();
    expect($message->fresh()->message)->toBe('Private details')
        ->and($message->fresh()->blocks)->toBe($blocks);
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

it('sends and deletes plain messages without file tables', function () {
    config(['chat.features' => []]);
    Schema::drop('chat_message_files');
    Schema::drop('chat_files');
    $room = Chatroom::create(['name' => 'Plain messages']);

    $message = $room->sendMessage('Hello');
    $payload = MessageResource::make($message)->resolve();
    $message->delete();

    expect($message->fresh()->message)->toBe('')
        ->and($payload)->not->toHaveKey('files');
});

it('supports blocks without file tables', function () {
    config(['chat.features' => []]);
    Schema::drop('chat_message_files');
    Schema::drop('chat_files');
    $room = Chatroom::create(['name' => 'Blocks only']);

    $message = $room->sendMessage('', ['blocks' => [
        ['schema_version' => 1, 'type' => 'section', 'block_id' => 'intro', 'text' => 'Hello'],
    ]]);
    $message->delete();

    expect($message->fresh()->message)->toBe('');
});

it('does not query file tables when force deleting participants or rooms with files disabled', function () {
    config(['chat.features' => []]);
    Schema::drop('chat_message_files');
    Schema::drop('chat_files');
    $room = Chatroom::create(['name' => 'No files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $participant->sendMessageTo($room, 'Hello');

    $participant->forceDelete();
    $room->forceDelete();

    expect(Chatroom::query()->whereKey($room->id)->exists())->toBeFalse();
});

it('omits loaded file data when files are disabled', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $message = $participant->sendMessageTo($room, 'File', messageConfiguration: ['files' => [$file->id]])->load('files');
    config(['chat.features' => []]);

    $payload = MessageResource::make($message)->resolve();

    expect($payload)->not->toHaveKey('files');
});

it('supports files when the feature is enabled', function () {
    config(['chat.features' => [Features::files()]]);
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files only']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);

    $message = $participant->sendMessageTo($room, '', messageConfiguration: ['files' => [$file->id]]);
    $message->delete();

    expect($message->fresh()->message)->toBe('')->and($file->fresh())->not->toBeNull();
});

it('attaches chatroom-owned files to system messages', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Generated reports']);
    $path = 'chat-files/report.txt';
    $content = 'Generated report';
    Storage::disk('local')->put($path, $content);
    $file = ChatFile::create([
        'owner_type' => $room->getMorphClass(),
        'owner_id' => $room->getKey(),
        'disk' => 'local',
        'path' => $path,
        'name' => 'report.txt',
        'mime_type' => 'text/plain',
        'size' => strlen($content),
        'sha256' => hash('sha256', $content),
        'status' => 'ready',
        'expires_at' => now()->addDay(),
    ]);

    $message = $room->sendMessage('Generated report', ['files' => [$file->getKey()]]);

    expect($message->sender_id)->toBeNull()
        ->and($message->files()->pluck('chat_files.id')->all())->toBe([$file->getKey()])
        ->and($file->fresh()->expires_at)->toBeNull();

    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'report-requester']);
    $participantFile = chatMessageTestFile($participant, 'private.txt');

    expect(fn () => $room->sendMessage('Another report', ['files' => [$participantFile->getKey()]]))
        ->toThrow(ValidationException::class);
    expect($room->messages()->count())->toBe(1);
});

it('attaches files when a message sender has been soft deleted', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $sender = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($sender);
    $sender->delete();

    $message = $room->sendMessageAs($sender, 'Attached before leaving', ['files' => [$file->getKey()]]);

    expect($message->files()->pluck('chat_files.id')->all())->toBe([$file->getKey()])
        ->and($file->fresh()->expires_at)->toBeNull();
});

it('requires file owners and omits unused file and share metadata', function () {
    expect(Schema::hasColumn('chat_files', 'metadata'))->toBeFalse()
        ->and(Schema::hasColumn('chat_message_files', 'title'))->toBeFalse()
        ->and(fn () => DB::table('chat_files')->insert([
            'disk' => 'local', 'path' => 'chat-files/orphan', 'name' => 'orphan.txt',
            'mime_type' => 'text/plain', 'size' => 1, 'sha256' => str_repeat('a', 64),
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
});

it('rejects file content when its feature is disabled', function () {
    config(['chat.features' => []]);
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Disabled features']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);

    expect(fn () => $participant->sendMessageTo($room, 'Text', messageConfiguration: ['files' => [$file->id]]))
        ->toThrow(LogicException::class);
    expect($room->messages()->count())->toBe(0);
});

it('does not prune files when the feature is disabled', function () {
    config(['chat.features' => []]);
    Schema::drop('chat_message_files');
    Schema::drop('chat_files');

    $this->artisan('le-chat:prune-files')->assertFailed();
});

it('reconciles file shares deleted while the feature was disabled', function (bool $forceDelete) {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $message = $participant->sendMessageTo($room, 'File', messageConfiguration: ['files' => [$file->id]]);
    config(['chat.features' => []]);

    $forceDelete ? $message->forceDelete() : $message->delete();
    config(['chat.features' => [Features::files()]]);
    $this->artisan('le-chat:prune-files')->assertSuccessful();

    expect($file->fresh()->messages()->count())->toBe(0)
        ->and($file->fresh()->expires_at)->not->toBeNull();
    Storage::disk('local')->assertExists($file->path);
})->with([false, true]);

it('retains active shares when reconciling deleted messages', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $deleted = $participant->sendMessageTo($room, 'First', messageConfiguration: ['files' => [$file->id]]);
    $active = $participant->sendMessageTo($room, 'Second', messageConfiguration: ['files' => [$file->id]]);
    config(['chat.features' => []]);
    $deleted->delete();
    config(['chat.features' => [Features::files()]]);

    $this->artisan('le-chat:prune-files')->assertSuccessful();

    expect($file->fresh()->messages()->pluck('chat_messages.id')->all())->toBe([$active->id])
        ->and($file->fresh()->expires_at)->toBeNull();
    Storage::disk('local')->assertExists($file->path);
});

it('shares files before announcing a message and unlinks them when deleted', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant, 'notes.txt');

    $message = $room->sendMessageAs($participant, 'See attached', ['files' => [$file->id]]);

    Storage::disk('local')->assertExists($file->path);
    expect(Storage::disk('local')->get($file->path))->toBe('Private notes')
        ->and($message->files()->sole()->name)->toBe('notes.txt');
    Event::assertDispatched(MessageCreated::class, fn (MessageCreated $event): bool => $event->message->files->count() === 1);
    Event::assertDispatchedTimes(MessageCreated::class, 1);

    $message->delete();

    Storage::disk('local')->assertExists($file->path);
    expect($message->files()->count())->toBe(0);
});

it('allows a file-only message from a participant', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant, 'document.pdf');

    $message = $participant->sendMessageTo($room, '', messageConfiguration: [
        'files' => [$file->id],
    ]);

    expect($message->fresh()->message)->toBe('')
        ->and($message->files()->count())->toBe(1);
    Event::assertDispatchedTimes(MessageCreated::class, 1);
});

it('uses a generic attachment preview without changing the message broadcast', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant, 'private-name.txt');
    $message = $participant->sendMessageTo($room, '', messageConfiguration: ['files' => [$file->id]]);

    foreach ([$message, $message->fresh()] as $notificationMessage) {
        $notification = new NewMessage($notificationMessage, $participant);

        expect($notification->toArray($participant)['message'])->toBe('Attachment')
            ->and($notification->toMail($participant)->introLines)->toContain('Unknown Sender: Attachment')
            ->and($notification->broadcastWith()['message'])->toBe('');

        if (class_exists(WebPushMessage::class)) {
            expect($notification->toWebPush($participant, null)->toArray()['body'])->toBe('Attachment');
        }
    }
});

it('preserves notification text and does not query disabled files', function () {
    $room = Chatroom::create(['name' => 'Messages']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $message = $room->sendMessageAs($participant, 'Hello');
    config(['chat.features' => []]);
    Schema::drop('chat_message_files');
    Schema::drop('chat_files');

    expect((new NewMessage($message, $participant))->toArray($participant)['message'])->toBe('Hello')
        ->and((new NewMessage($room->sendMessage(''), $participant))->toArray($participant)['message'])->toBe('');
});

it('retains shared files on force deletion', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $message = $room->sendMessageAs($participant, '', ['files' => [$file->id]]);

    $message->forceDelete();

    Storage::disk('local')->assertExists($file->path);
    expect($file->fresh())->not->toBeNull()->and($file->messages()->count())->toBe(0);
});

it('retains independent files when message creation fails', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    ChatMessage::creating(fn () => throw new RuntimeException('Creation failed'));

    try {
        expect(fn () => $room->sendMessageAs($participant, 'File', ['files' => [$file->id]]))
            ->toThrow(RuntimeException::class, 'Creation failed');
        Storage::disk('local')->assertExists($file->path);
        expect($room->messages()->count())->toBe(0);
    } finally {
        ChatMessage::flushEventListeners();
    }
});

it('does not let a direct participant send to another room', function () {
    $room = Chatroom::create(['name' => 'First']);
    $other = Chatroom::create(['name' => 'Second']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);

    expect(fn () => $participant->sendMessageTo($other, 'Not allowed'))->toThrow(Exception::class);
    expect($other->messages()->count())->toBe(0);
});

it('retains file references when pruning a file fails', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $file->update(['expires_at' => now()->subMinute()]);
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->once()->with($file->path)->andReturnFalse();
    Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);

    $this->artisan('le-chat:prune-files')->assertFailed();
    expect($file->fresh())->not->toBeNull()->and($file->fresh()->status)->toBe('deleting');
});

it('prunes expired unshared files with Laravel model pruning', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $file->update(['expires_at' => now()->subMinute()]);

    $this->artisan('model:prune', ['--model' => [ChatFile::class]])->assertSuccessful();

    Storage::disk('local')->assertMissing($file->path);
    expect($file->fresh())->toBeNull();
});

it('does not prune files through Laravel when the files feature is disabled', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $file->update(['expires_at' => now()->subMinute()]);
    config(['chat.features' => []]);

    $this->artisan('model:prune', ['--model' => [ChatFile::class]])->assertSuccessful();

    Storage::disk('local')->assertExists($file->path);
    expect($file->fresh())->not->toBeNull();
});

it('sends shared files in replies', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Replies']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant, 'reply.txt');
    $original = $room->sendMessage('Original');

    $reply = $original->replyAs($participant, '', ['files' => [$file->id]]);

    expect($reply->reply_to_id)->toBe($original->getKey())
        ->and($reply->files()->count())->toBe(1);
});

it('unlinks shared files when their room is force deleted', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $room->sendMessageAs($participant, 'File', ['files' => [$file->id]]);

    $room->forceDelete();

    Storage::disk('local')->assertExists($file->path);
    expect($file->fresh())->not->toBeNull()->and($file->messages()->count())->toBe(0);
});

it('unlinks sent files when their participant is force deleted', function () {
    Storage::fake('local');
    $room = Chatroom::create(['name' => 'Files']);
    $participant = ChatParticipant::create(['chatroom_id' => $room->getKey(), 'reference_id' => 'sender']);
    $file = chatMessageTestFile($participant);
    $room->sendMessageAs($participant, 'File', ['files' => [$file->id]]);

    $participant->forceDelete();

    Storage::disk('local')->assertExists($file->path);
    expect($file->fresh())->not->toBeNull()->and($file->messages()->count())->toBe(0);
});
