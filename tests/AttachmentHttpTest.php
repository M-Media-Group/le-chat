<?php

use Aws\S3\S3Client;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mmedia\LeChat\Ai\ApprovalBatches;
use Mmedia\LeChat\Contracts\ApprovalPolicy;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\BlockInteracted;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Http\Controllers\ChatroomController;
use Mmedia\LeChat\Models\ChatFile;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;
use Mmedia\LeChat\Traits\IsChatParticipant;

/** @implements ChatParticipantInterface<AttachmentTestUser> */
class AttachmentTestUser extends Authenticatable implements ChatParticipantInterface
{
    use IsChatParticipant;

    protected $table = 'attachment_test_users';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('attachment_test_users', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
    Storage::fake('local');
    Event::fake([MessageCreated::class, ParticipantCreated::class]);
    config(['chat.create_system_messages' => false]);
    $this->withoutMiddleware(Authenticate::class);
});

function readyAttachmentFile(AttachmentTestUser $owner, string $name, string $content): ChatFile
{
    $path = 'chat-files/'.uniqid('', true);
    Storage::disk('local')->put($path, $content);

    return ChatFile::create([
        'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(),
        'disk' => 'local', 'path' => $path, 'name' => $name, 'mime_type' => 'text/plain',
        'size' => strlen($content), 'sha256' => hash('sha256', $content), 'status' => 'ready',
        'expires_at' => now()->addDay(),
    ]);
}

it('uploads a file-only message and allows only visible participants to download it', function () {
    $room = Chatroom::create(['name' => 'Private']);
    $sender = AttachmentTestUser::create();
    $reader = AttachmentTestUser::create();
    $outsider = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $room->addParticipant($reader);

    $file = readyAttachmentFile($sender, 'notes.txt', 'Private notes');
    $response = $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom',
        'to_entity_id' => $room->id,
        'files' => [$file->id],
    ]);

    $response->assertCreated()->assertJsonPath('message', '')->assertJsonPath('files.0.name', 'notes.txt')->assertJsonMissingPath('files.0.path');
    $this->getJson(route('messages.show', $response->json('id')))->assertOk()->assertJsonPath('data.message', '');
    $download = $response->json('files.0.url');
    $this->actingAs($reader)->get($download)->assertOk();
    $this->actingAs($outsider)->get($download)->assertNotFound();

    $room->messages()->findOrFail($response->json('id'))->delete();
    $this->actingAs($reader)->get($download)->assertNotFound();
});

it('keeps ordinary HTTP messaging available when files are disabled', function () {
    config(['chat.features' => []]);
    Schema::drop('chat_message_files');
    Schema::drop('chat_files');
    $room = Chatroom::create(['name' => 'No files']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);

    $response = $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'message' => 'Hello',
    ])->assertCreated()->assertJsonMissingPath('files');
    $this->getJson('/api/chatrooms/'.$room->id)->assertOk()->assertJsonMissingPath('data.messages.0.files');
    $this->getJson(route('messages.show', $response->json('id')))
        ->assertOk()->assertJsonMissingPath('data.files');
    $this->getJson('/api/chatrooms')->assertOk();
    $this->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'message' => 'Hello', 'files' => [1],
    ])->assertUnprocessable()->assertJsonValidationErrors(['files']);
    $this->postJson('/api/files', [])->assertNotFound();
    $this->getJson('/api/files/1')->assertNotFound();
    $room->forceDelete();
});

it('rejects empty messages and does not register a room-scoped send alias', function () {
    $room = Chatroom::create(['name' => 'One']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);

    $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['message']);

    $this->postJson('/api/chatrooms/'.$room->id.'/messages', [])->assertNotFound();
});

it('matches a model-bound chatroom route against the submitted target ID', function () {
    Route::model('chatroom', Chatroom::class);
    Route::post('/test-chatrooms/{chatroom}/messages', [ChatroomController::class, 'storeMessage']);
    $room = Chatroom::create(['name' => 'Route-bound room']);
    $otherRoom = Chatroom::create(['name' => 'Other room']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $payload = ['to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'message' => 'Hello'];

    $this->actingAs($sender)->postJson('/test-chatrooms/'.$room->id.'/messages', $payload)->assertCreated();
    $this->postJson('/test-chatrooms/'.$room->id.'/messages', [
        ...$payload,
        'to_entity_id' => $otherRoom->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['to_entity_id']);

    expect($room->messages()->count())->toBe(1)
        ->and($otherRoom->messages()->count())->toBe(0);
});

it('denies downloads for messages outside the membership period', function () {
    $room = Chatroom::create(['name' => 'Private']);
    $sender = AttachmentTestUser::create();
    $reader = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $membership = $room->addParticipant($reader);
    $membership->forceFill(['created_at' => now()->addMinute()])->saveQuietly();
    $file = readyAttachmentFile($sender, 'file.txt', 'File');
    $message = $sender->sendMessageTo($room, 'File', messageConfiguration: ['files' => [$file->id]]);
    $file = $message->files()->sole();

    $this->actingAs($reader)->get('/api/files/'.$file->id)->assertNotFound();
});

it('matches file downloads to message visibility including history settings and leave boundaries', function (bool $includeHistory) {
    $this->freezeSecond();
    config(['chat.can_see_messages_before_joined' => $includeHistory]);
    $room = Chatroom::create(['name' => 'Membership history']);
    $sender = AttachmentTestUser::create();
    $reader = AttachmentTestUser::create();
    $outsider = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $membership = $room->addParticipant($reader);
    $joinedAt = now()->subMinutes(3);
    $leftAt = now()->subMinute();
    $membership->forceFill(['created_at' => $joinedAt, 'deleted_at' => $leftAt])->saveQuietly();
    $visibleIds = [];

    foreach ([
        [$joinedAt->copy()->subSecond(), $includeHistory],
        [$joinedAt, true],
        [$joinedAt->copy()->addMinute(), true],
        [$leftAt, true],
        [$leftAt->copy()->addSecond(), false],
    ] as [$sentAt, $visible]) {
        $file = readyAttachmentFile($sender, 'notes.txt', 'Private notes');
        $message = $sender->sendMessageTo($room, 'Notes', messageConfiguration: ['files' => [$file->id]]);
        $message->forceFill(['created_at' => $sentAt])->saveQuietly();
        $messageUrl = route('messages.show', $message->id);
        $fileUrl = route('chat.files.show', $file->id);

        $this->actingAs($reader);
        if ($visible) {
            $visibleIds[] = $message->id;
            $this->getJson($messageUrl)->assertOk()->assertJsonPath('data.files.0.id', $file->id);
            $this->get($fileUrl)->assertOk()->assertStreamedContent('Private notes');
        } else {
            $this->getJson($messageUrl)->assertNotFound();
            $this->get($fileUrl)->assertNotFound();
        }
        $this->actingAs($outsider)->get($fileUrl)->assertNotFound();
    }

    $response = $this->actingAs($reader)->getJson('/api/chatrooms/'.$room->id)->assertOk();
    expect(array_column($response->json('data.messages'), 'id'))->toBe($visibleIds);
})->with([
    'without earlier history' => [false],
    'with earlier history' => [true],
]);

it('applies the same PHP visibility overrides to files and messages', function (bool $asMembership) {
    $this->freezeSecond();
    $room = Chatroom::create(['name' => 'History overrides']);
    $sender = AttachmentTestUser::create();
    $reader = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $membership = $room->addParticipant($reader);
    $membership->forceFill(['created_at' => now()->subMinute(), 'deleted_at' => now()])->saveQuietly();
    $actor = $asMembership ? $membership : $reader;
    $file = readyAttachmentFile($sender, 'before.txt', 'Before joining');
    $message = $sender->sendMessageTo($room, 'Before joining', messageConfiguration: ['files' => [$file->id]]);
    $message->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();

    foreach ([false, true] as $includeHistory) {
        config(['chat.can_see_messages_before_joined' => $includeHistory]);
        expect(ChatFile::query()->visibleTo($actor)->pluck('id')->all())->toBe($includeHistory ? [$file->id] : []);
        expect($actor->getMessages()->pluck('id')->all())->toBe($includeHistory ? [$message->id] : []);
        expect($actor->visibleMessages()->pluck('id')->all())->toBe($includeHistory ? [$message->id] : []);

        expect(ChatFile::query()->visibleTo($actor, includeBeforeJoined: true)->pluck('id')->all())->toBe([$file->id]);
        expect(ChatMessage::query()->visibleTo($actor, includeBeforeJoined: true)->pluck('id')->all())->toBe([$message->id]);
        expect($actor->getMessages(includeBeforeJoined: true)->pluck('id')->all())->toBe([$message->id]);
        expect(ChatFile::query()->visibleTo($actor, includeBeforeJoined: false)->get())->toBeEmpty();
        expect(ChatMessage::query()->visibleTo($actor, includeBeforeJoined: false)->get())->toBeEmpty();
        expect($actor->getMessages(includeBeforeJoined: false))->toBeEmpty();
        expect(ChatFile::query()->visibleTo($actor, includeLeftChatrooms: false, includeBeforeJoined: true)->get())->toBeEmpty();
    }
})->with([
    'model' => [false],
    'membership' => [true],
]);

it('grants file access through any visible share but not through hidden remaining shares', function () {
    $this->freezeSecond();
    $room = Chatroom::create(['name' => 'Shared history']);
    $sender = AttachmentTestUser::create();
    $reader = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $membership = $room->addParticipant($reader);
    $membership->forceFill(['created_at' => now()->subMinute()])->saveQuietly();
    $file = readyAttachmentFile($sender, 'notes.txt', 'Private notes');
    $beforeJoin = $sender->sendMessageTo($room, 'Old share', messageConfiguration: ['files' => [$file->id]]);
    $beforeJoin->forceFill(['created_at' => now()->subMinutes(2)])->saveQuietly();
    $url = route('chat.files.show', $file->id);
    $this->actingAs($reader)->get($url)->assertNotFound();

    $visible = $sender->sendMessageTo($room, 'Visible share', messageConfiguration: ['files' => [$file->id]]);
    $this->get($url)->assertOk()->assertStreamedContent('Private notes');
    $visible->delete();
    $this->get($url)->assertNotFound();

    config(['chat.can_see_messages_before_joined' => true]);
    $this->get($url)->assertOk()->assertStreamedContent('Private notes');
    $beforeJoin->delete();
    $this->get($url)->assertNotFound();
});

it('accepts files through the generic message route', function () {
    $room = Chatroom::create(['name' => 'Private']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);

    $file = readyAttachmentFile($sender, 'document.txt', 'Document');
    $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom',
        'to_entity_id' => $room->id,
        'message' => 'Document',
        'files' => [$file->id],
    ])->assertCreated()->assertJsonPath('files.0.name', 'document.txt');

    expect($room->messages()->count())->toBe(1);
});

it('sends and retrieves a block-only message through the API', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $blocks = [['schema_version' => 1, 'type' => 'section', 'block_id' => 'intro', 'text' => 'Hello']];

    $response = $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'blocks' => $blocks,
    ]);

    $response->assertCreated()->assertJsonPath('message', 'Hello')->assertJsonPath('blocks.0.text', 'Hello')->assertJsonPath('version', 1);
    $this->getJson(route('messages.show', $response->json('id')))
        ->assertOk()->assertJsonPath('data.blocks.0.block_id', 'intro');
});

it('rejects invalid blocks through the message API', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);

    $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id,
        'blocks' => [['schema_version' => 1, 'type' => 'unknown', 'block_id' => 'bad']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['blocks.0.type']);

    expect($room->messages()->count())->toBe(0);
});

it('requires authorization and the current version to edit a message', function () {
    $room = Chatroom::create(['name' => 'Rich messages']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $message = $sender->sendMessageTo($room, 'Original');
    $url = '/api/messages/'.$message->id;

    $this->actingAs($sender)->patchJson($url, ['version' => 1, 'message' => 'Changed'])->assertForbidden();
    Gate::define('le-chat.update-message', fn (AttachmentTestUser $user, ChatMessage $target): bool => $user->id === $sender->id);
    $this->patchJson($url, ['version' => 1, 'message' => 'Changed'])->assertOk()->assertJsonPath('version', 2);
    $this->patchJson($url, ['version' => 1, 'message' => 'Stale'])->assertConflict();

    expect($message->fresh()->message)->toBe('Changed')
        ->and($message->fresh()->version)->toBe(2)
        ->and($message->fresh()->edited_at)->not->toBeNull();
});

it('shares a verified file across messages without deleting the other share', function () {
    $room = Chatroom::create(['name' => 'Shared files']);
    $sender = AttachmentTestUser::create();
    $reader = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $room->addParticipant($reader);

    $file = readyAttachmentFile($sender, 'notes.txt', 'Private notes');
    $fileId = $file->id;
    $first = $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$fileId],
    ])->assertCreated();
    $second = $this->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$fileId],
    ])->assertCreated();

    $firstUrl = $first->json('files.0.url');
    $secondUrl = $second->json('files.0.url');
    expect($firstUrl)->toBe($secondUrl);
    $this->actingAs($reader)->get($secondUrl)->assertOk();
    $room->messages()->findOrFail($first->json('id'))->delete();
    $this->get($firstUrl)->assertOk();
    $this->get($secondUrl)->assertOk();
    expect(ChatFile::findOrFail($fileId)->messages()->count())->toBe(1);

    $room->messages()->findOrFail($second->json('id'))->delete();
    $this->get($firstUrl)->assertNotFound();
});

it('rejects file shares from a non-owner or for a file that is not ready', function () {
    $room = Chatroom::create(['name' => 'Shared files']);
    $owner = AttachmentTestUser::create();
    $other = AttachmentTestUser::create();
    $room->addParticipant($owner);
    $room->addParticipant($other);
    $file = readyAttachmentFile($owner, 'notes.txt', 'Private notes');

    $this->actingAs($other)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$file->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['files']);
    $file->update(['status' => 'pending']);
    $this->actingAs($owner)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$file->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['files']);

    expect($room->messages()->count())->toBe(0);
});

it('returns shared files when loading a chatroom', function () {
    $room = Chatroom::create(['name' => 'Shared files']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $file = readyAttachmentFile($sender, 'notes.txt', 'Private notes');
    $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$file->id],
    ])->assertCreated();

    $this->getJson('/api/chatrooms/'.$room->id)->assertOk()->assertJsonPath('data.messages.0.files.0.name', 'notes.txt');
});

it('retains a file but expires it after its chatroom is force deleted', function () {
    $room = Chatroom::create(['name' => 'Shared files']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $file = readyAttachmentFile($sender, 'notes.txt', 'Private notes');
    $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$file->id],
    ])->assertCreated();

    $room->forceDelete();

    expect($file->fresh())->not->toBeNull()
        ->and($file->fresh()->expires_at)->not->toBeNull()
        ->and($file->messages()->count())->toBe(0);
    Storage::disk('local')->assertExists($file->path);
});

it('rejects staged uploads whose bytes do not match their declarations', function (array $declarations) {
    $sender = AttachmentTestUser::create();
    $content = 'Private notes';
    $payload = [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => strlen($content),
        'sha256' => hash('sha256', $content), ...$declarations,
    ];
    $created = $this->actingAs($sender)->postJson('/api/files', $payload)->assertCreated();
    $this->post($created->json('upload_url'), [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', $content),
    ], ['Accept' => 'application/json'])->assertOk();

    $this->patchJson('/api/files/'.$created->json('id'))->assertUnprocessable();

    expect(ChatFile::findOrFail($created->json('id'))->status)->toBe('pending')
        ->and(Storage::disk('local')->allFiles('chat-files/ready'))->toBe([]);
})->with([
    'size' => [['size' => 999]],
    'checksum' => [['sha256' => str_repeat('0', 64)]],
    'mime' => [['mime_type' => 'application/pdf']],
]);

it('enforces the independent file upload size limit', function () {
    $sender = AttachmentTestUser::create();
    config(['chat.features' => [Features::files(['max_size' => 1])]]);

    $this->actingAs($sender)->postJson('/api/files', [
        'name' => 'large.txt', 'mime_type' => 'text/plain', 'size' => 2048,
        'sha256' => hash('sha256', str_repeat('x', 2048)),
    ])->assertUnprocessable()->assertJsonValidationErrors(['size']);

    expect(ChatFile::count())->toBe(0);
});

it('prunes expired unshared files without removing shared files', function () {
    $room = Chatroom::create(['name' => 'Shared files']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $unshared = readyAttachmentFile($sender, 'unshared.txt', 'Unshared');
    $shared = readyAttachmentFile($sender, 'shared.txt', 'Shared');
    $this->actingAs($sender)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$shared->id],
    ])->assertCreated();
    $unsharedFile = $unshared;
    $sharedFile = $shared;
    $unsharedFile->update(['expires_at' => now()->subMinute()]);
    $sharedFile->update(['expires_at' => now()->subMinute()]);

    $this->artisan('le-chat:prune-files')->assertSuccessful();

    Storage::disk('local')->assertMissing($unsharedFile->path);
    Storage::disk('local')->assertExists($sharedFile->path);
    expect($unsharedFile->fresh())->toBeNull()->and($sharedFile->fresh())->not->toBeNull();
});

it('enforces file share limits for PHP callers', function () {
    $room = Chatroom::create(['name' => 'Shared files']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $first = readyAttachmentFile($sender, 'one.txt', 'One');
    $second = readyAttachmentFile($sender, 'two.txt', 'Two');
    config(['chat.features' => [Features::files(['max_files' => 1])]]);

    expect(fn () => $sender->sendMessageTo($room, '', messageConfiguration: ['files' => [$first->id, $second->id]]))
        ->toThrow(ValidationException::class);
    expect($room->messages()->count())->toBe(0);
});

it('cannot attach an expired unshared file', function () {
    $room = Chatroom::create(['name' => 'Shared files']);
    $owner = AttachmentTestUser::create();
    $room->addParticipant($owner);
    $file = readyAttachmentFile($owner, 'notes.txt', 'Private notes');
    $file->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($owner)->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$file->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['files']);
});

it('verifies a staged upload before making it ready', function () {
    $owner = AttachmentTestUser::create();
    $content = 'Private notes';
    $created = $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => strlen($content), 'sha256' => hash('sha256', $content),
    ])->assertCreated()->assertJsonPath('status', 'pending');
    $id = $created->json('id');

    $this->patchJson('/api/files/'.$id)->assertUnprocessable();
    $this->post($created->json('upload_url'), [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', $content),
    ], ['Accept' => 'application/json'])->assertOk();
    $this->patchJson('/api/files/'.$id)->assertOk()->assertJsonPath('status', 'ready');

    $stored = ChatFile::findOrFail($id);
    expect($stored->status)->toBe('ready');
    Storage::disk('local')->assertExists($stored->path);
});

it('uses the consumer disk throughout the file lifecycle despite later configuration changes', function (array $options) {
    $this->freezeSecond();
    Storage::fake('consumer-files');
    config([
        'filesystems.default' => isset($options['disk']) ? 'local' : 'consumer-files',
        'chat.features' => [Features::files($options)],
    ]);
    $room = Chatroom::create(['name' => 'Consumer storage']);
    $owner = AttachmentTestUser::create();
    $room->addParticipant($owner);
    $content = 'Private notes';

    $created = $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => strlen($content), 'sha256' => hash('sha256', $content),
    ])->assertCreated();
    $file = ChatFile::findOrFail($created->json('id'));
    expect($file->disk)->toBe('consumer-files');
    $pendingPath = $file->path;
    config(['filesystems.default' => 'local', 'chat-options.files.disk' => 'local']);

    $this->post($created->json('upload_url'), [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', $content),
    ], ['Accept' => 'application/json'])->assertOk();
    Storage::disk('consumer-files')->assertExists($pendingPath);
    $this->patchJson('/api/files/'.$file->id)->assertOk()->assertJsonPath('status', 'ready');
    $file->refresh();
    Storage::disk('consumer-files')->assertExists($file->path);
    Storage::disk('consumer-files')->assertMissing($pendingPath);
    Storage::disk('local')->assertDirectoryEmpty('chat-files');

    $message = $this->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$file->id],
    ])->assertCreated();
    $this->get($message->json('files.0.url'))->assertOk()->assertStreamedContent($content);
    $room->messages()->findOrFail($message->json('id'))->delete();
    $this->travel(25)->hours();
    $this->artisan('le-chat:prune-files')->assertSuccessful();
    Storage::disk('consumer-files')->assertMissing($file->path);
    expect($file->fresh())->toBeNull();
})->with([
    'inherited default' => [[]],
    'null inherits default' => [['disk' => null]],
    'explicit override' => [['disk' => 'consumer-files']],
]);

it('rejects a public consumer disk before creating an upload', function (array $options) {
    Storage::fake('consumer-public', ['visibility' => 'public']);
    config([
        'filesystems.default' => isset($options['disk']) ? 'local' : 'consumer-public',
        'chat.features' => [Features::files($options)],
    ]);
    $owner = AttachmentTestUser::create();

    $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => 4, 'sha256' => hash('sha256', 'Test'),
    ])->assertServerError();

    expect(ChatFile::count())->toBe(0);
})->with([
    'inherited default' => [[]],
    'explicit override' => [['disk' => 'consumer-public']],
]);

it('limits pending uploads per owner', function () {
    $owner = AttachmentTestUser::create();
    config(['chat.features' => [Features::files(['max_pending_uploads' => 1])]]);
    $payload = ['name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => 4, 'sha256' => hash('sha256', 'Test')];

    $this->actingAs($owner)->postJson('/api/files', $payload)->assertCreated();
    $this->postJson('/api/files', $payload)->assertUnprocessable()->assertJsonValidationErrors(['file']);
    expect(ChatFile::count())->toBe(1);
});

it('uses feature options for file paths and retention periods', function () {
    $this->freezeSecond();
    $owner = AttachmentTestUser::create();
    config(['chat.features' => [Features::files([
        'path' => 'custom-files', 'pending_ttl_hours' => 2, 'unshared_ttl_hours' => 3,
    ])]]);
    $content = 'Test';

    $created = $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => strlen($content), 'sha256' => hash('sha256', $content),
    ])->assertCreated();
    $pending = ChatFile::findOrFail($created->json('id'));
    expect($pending->path)->toStartWith('custom-files/pending/')
        ->and($pending->expires_at->equalTo(now()->addHours(2)))->toBeTrue();
    $this->post($created->json('upload_url'), [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', $content),
    ], ['Accept' => 'application/json'])->assertOk();
    $this->patchJson('/api/files/'.$pending->id)->assertOk();

    $ready = $pending->fresh();
    expect($ready->path)->toStartWith('custom-files/ready/')
        ->and($ready->expires_at->equalTo(now()->addHours(3)))->toBeTrue();
});

it('enforces the unshared quota when pending uploads are finalized', function () {
    $room = Chatroom::create(['name' => 'Quota']);
    $owner = AttachmentTestUser::create();
    $room->addParticipant($owner);
    config(['chat.features' => [Features::files(['max_unshared_files' => 1])]]);
    $payload = ['name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => 4, 'sha256' => hash('sha256', 'Test')];
    $first = $this->actingAs($owner)->postJson('/api/files', $payload)->assertCreated();
    $second = $this->postJson('/api/files', $payload)->assertCreated();
    foreach ([$first, $second] as $pending) {
        $this->post($pending->json('upload_url'), [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Test'),
        ], ['Accept' => 'application/json'])->assertOk();
    }
    $this->patchJson('/api/files/'.$first->json('id'))->assertOk();

    $this->patchJson('/api/files/'.$second->json('id'))->assertUnprocessable();
    $this->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$first->json('id')],
    ])->assertCreated();
    $this->patchJson('/api/files/'.$second->json('id'))->assertOk();
});

it('rejects tampered staged uploads and requests from other users', function () {
    $owner = AttachmentTestUser::create();
    $other = AttachmentTestUser::create();
    $created = $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => 4, 'sha256' => hash('sha256', 'Good'),
    ])->assertCreated();
    $id = $created->json('id');

    $this->actingAs($other)->patchJson('/api/files/'.$id)->assertNotFound();
    $this->post($created->json('upload_url'), [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Good'),
    ], ['Accept' => 'application/json'])->assertNotFound();
    $this->actingAs($owner)->post($created->json('upload_url'), [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Evil'),
    ], ['Accept' => 'application/json'])->assertOk();
    $this->patchJson('/api/files/'.$id)->assertUnprocessable();

    expect(ChatFile::findOrFail($id)->status)->toBe('pending')
        ->and(Storage::disk('local')->allFiles('chat-files/ready'))->toBe([]);
});

it('rejects staged uploads that exceed their declared size', function () {
    $owner = AttachmentTestUser::create();
    $created = $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => 4, 'sha256' => hash('sha256', 'Good'),
    ])->assertCreated();
    $this->post($created->json('upload_url'), [
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Longer'),
    ], ['Accept' => 'application/json'])->assertOk();

    $this->patchJson('/api/files/'.$created->json('id'))->assertUnprocessable();

    expect(ChatFile::findOrFail($created->json('id'))->status)->toBe('pending')
        ->and(Storage::disk('local')->allFiles('chat-files/ready'))->toBe([]);
});

it('detects MIME types when a large metadata prefix precedes the file signature', function () {
    $owner = AttachmentTestUser::create();
    $tagSize = 70000;
    $synchsafeSize = chr(($tagSize >> 21) & 127).chr(($tagSize >> 14) & 127).chr(($tagSize >> 7) & 127).chr($tagSize & 127);
    $content = 'ID3'.chr(3).chr(0).chr(0).$synchsafeSize.str_repeat("\0", $tagSize)."\xff\xfb\x90\x64".str_repeat("\0", 1000);
    $created = $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'long-id3.mp3',
        'mime_type' => 'audio/mpeg',
        'size' => strlen($content),
        'sha256' => hash('sha256', $content),
    ])->assertCreated();

    $this->post($created->json('upload_url'), [
        'file' => UploadedFile::fake()->createWithContent('long-id3.mp3', $content),
    ], ['Accept' => 'application/json'])->assertOk();

    $this->patchJson('/api/files/'.$created->json('id'))->assertOk()->assertJsonPath('status', 'ready');

    expect(ChatFile::findOrFail($created->json('id'))->mime_type)->toBe('audio/mpeg');
});

it('returns a size and checksum constrained upload policy for an S3 disk', function (array $options) {
    $owner = AttachmentTestUser::create();
    config([
        'chat.features' => [Features::files($options)],
        'filesystems.default' => isset($options['disk']) ? 'local' : 's3-test',
        'filesystems.disks.s3-test' => [
            'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret',
            'region' => 'us-east-1', 'bucket' => 'chat', 'endpoint' => 'http://127.0.0.1:9000',
            'use_path_style_endpoint' => true,
        ],
    ]);
    $checksum = hash('sha256', 'Private notes');

    $response = $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => 13, 'sha256' => $checksum,
    ])->assertCreated()->assertJsonPath('upload_method', 'POST');

    $fields = $response->json('upload_fields');
    $policy = json_decode(base64_decode($fields['Policy']), true);
    expect($policy['conditions'])->toContain(['content-length-range', 13, 13])
        ->toContain(['x-amz-checksum-sha256' => base64_encode(hex2bin($checksum))]);
})->with([
    'inherited default' => [[]],
    'explicit override' => [['disk' => 's3-test']],
]);

it('uploads to an S3 compatible store and verifies the finalized file', function () {
    $endpoint = getenv('LE_CHAT_S3_ENDPOINT');
    if ($endpoint === false || $endpoint === '') {
        $this->markTestSkipped('Set LE_CHAT_S3_ENDPOINT to run the S3 integration test.');
    }
    $credentials = ['key' => 'chat-test', 'secret' => 'chat-test-password'];
    $client = new S3Client([
        'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint,
        'use_path_style_endpoint' => true, 'credentials' => $credentials,
    ]);
    $bucket = 'le-chat-test';
    if (! $client->doesBucketExistV2($bucket)) {
        $client->createBucket(['Bucket' => $bucket]);
    }
    config([
        'chat.features' => [Features::files(['disk' => 's3-integration'])],
        'filesystems.disks.s3-integration' => [
            'driver' => 's3', ...$credentials, 'region' => 'us-east-1', 'bucket' => $bucket,
            'endpoint' => $endpoint, 'use_path_style_endpoint' => true,
        ],
    ]);
    $room = Chatroom::create(['name' => 'Shared files']);
    $owner = AttachmentTestUser::create();
    $room->addParticipant($owner);
    $content = 'Private notes';

    $created = $this->actingAs($owner)->postJson('/api/files', [
        'name' => 'notes.txt', 'mime_type' => 'text/plain', 'size' => strlen($content), 'sha256' => hash('sha256', $content),
    ])->assertCreated();
    $wrongSize = Http::attach('file', 'Short', 'notes.txt')->post($created->json('upload_url'), $created->json('upload_fields'));
    $wrongChecksum = Http::attach('file', 'Corrupt notes', 'notes.txt')->post($created->json('upload_url'), $created->json('upload_fields'));
    expect($wrongSize->failed())->toBeTrue()->and($wrongChecksum->failed())->toBeTrue();
    Http::attach('file', $content, 'notes.txt')->post($created->json('upload_url'), $created->json('upload_fields'))->throw();
    $this->patchJson('/api/files/'.$created->json('id'))->assertOk();
    $message = $this->postJson('/api/messages', [
        'to_entity_type' => 'chatroom', 'to_entity_id' => $room->id, 'files' => [$created->json('id')],
    ])->assertCreated();
    $this->get($message->json('files.0.url'))->assertOk()->assertStreamedContent($content);
    $room->messages()->findOrFail($message->json('id'))->delete();
    $this->get($message->json('files.0.url'))->assertNotFound();
});

it('restricts default approvals to the original requester over HTTP', function () {
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = AttachmentTestUser::create();
    $other = AttachmentTestUser::create();
    $room->addParticipant($other);
    $room->addParticipant($requester);
    $request = $requester->sendMessageTo($room, 'Run a tool');
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:Example']);
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => 'Example', 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [
            ['id' => 'previous', 'name' => 'example_tool', 'arguments' => ['target' => 'old'], 'approval_reason' => 'Review', 'result' => '{"error":"Private validation error"}'],
            ['id' => 'call-1', 'name' => 'example_tool', 'arguments' => ['target' => 'private'], 'approval_reason' => 'Review'],
        ], 'replay_blocks' => []]],
    ]]]);
    $batch = app(ApprovalBatches::class)->create($request, $paused);
    $url = '/api/approvals/'.$batch->id;
    $payload = ['idempotency_key' => 'request-1', 'decisions' => ['call-1' => ['action' => 'approve']]];

    $this->actingAs($other)->getJson($url)->assertForbidden();
    $this->postJson($url, $payload)->assertForbidden();
    $this->actingAs($requester)->getJson($url)->assertOk()->assertJsonPath('approvals.0.arguments.target', 'private')
        ->assertJsonPath('approvals.0.reason', 'Review Previous attempt failed: Private validation error');
    expect($batch->fresh()->status)->toBe('pending');
});

it('shows a stopped action failure only to the approver and never broadcasts it', function () {
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = AttachmentTestUser::create();
    $other = AttachmentTestUser::create();
    $room->addParticipant($requester);
    $room->addParticipant($other);
    $request = $requester->sendMessageTo($room, 'Run a tool');
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:Example']);
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => 'Example', 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => [], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    $batch = app(ApprovalBatches::class)->create($request, $paused);
    $batch->update(['status' => 'resumed', 'approver_type' => $requester->getMorphClass(), 'approver_id' => $requester->getKey(), 'failure_reason' => 'The end must be after the start.']);
    $paused->refresh()->updateContent($paused->version, ['message' => 'The previous action failed. The retry was stopped.', 'blocks' => null]);

    $this->actingAs($requester)->getJson(route('messages.show', $paused->id))->assertOk()
        ->assertJsonPath('data.message', "The previous action failed. The retry was stopped.\nReason: The end must be after the start.");
    expect(json_encode((new MessageUpdated($paused->fresh()->load('approvalBatch')))->broadcastWith()))
        ->not->toContain('The end must be after the start.');
    $this->actingAs($other)->getJson(route('messages.show', $paused->id))->assertOk()
        ->assertJsonPath('data.message', 'The previous action failed. The retry was stopped.');
});

it('matches requester identity across model types and requires an active requester', function () {
    $room = Chatroom::create(['name' => 'Approval']);
    $otherRoom = Chatroom::create(['name' => 'Other']);
    $requester = AttachmentTestUser::create();
    $other = AttachmentTestUser::create();
    $room->addParticipant($other);
    $room->addParticipant($requester);
    $otherRoom->addParticipant($requester);
    $request = $requester->sendMessageTo($room, 'Run a tool');
    $sender = $requester->asParticipantIn($room);
    $otherParticipant = $other->asParticipantIn($room);
    $sameModelOtherRoom = $requester->asParticipantIn($otherRoom);
    $policy = app(ApprovalPolicy::class);

    expect($otherParticipant->getKey())->toBe($requester->getKey())
        ->and($policy->mayApprove($requester, $request, []))->toBeTrue()
        ->and($policy->mayApprove($sender, $request, []))->toBeTrue()
        ->and($policy->mayApprove($other, $request, []))->toBeFalse()
        ->and($policy->mayApprove($otherParticipant, $request, []))->toBeFalse()
        ->and($policy->mayApprove($sameModelOtherRoom, $request, []))->toBeFalse()
        ->and($policy->mayApprove(new AttachmentTestUser, $request, []))->toBeFalse()
        ->and($policy->mayResume($request, []))->toBeTrue();

    $sender->delete();
    expect($policy->mayApprove($requester, $request, []))->toBeFalse()
        ->and($policy->mayResume($request, []))->toBe('The requester is no longer a chatroom participant.');

    $sender->restore();
    $requester->delete();
    expect($policy->mayResume($request, []))->toBe('The requester is no longer available.');
});

it('restricts approval details and records decisions without escalating requester permissions', function () {
    $room = Chatroom::create(['name' => 'Approval']);
    $requester = AttachmentTestUser::create();
    $other = AttachmentTestUser::create();
    $room->addParticipant($requester);
    $room->addParticipant($other);
    $request = $requester->sendMessageTo($room, 'Run a tool');
    $agent = ChatParticipant::create(['chatroom_id' => $room->id, 'reference_id' => 'ai-agent:Example']);
    $paused = $room->sendMessageAs($agent, '', ['metadata' => ['ai' => [
        'role' => 'assistant', 'agent' => 'Example', 'status' => 'paused',
        'steps' => [['content' => '', 'tool_calls' => [[
            'id' => 'call-1', 'name' => 'example_tool', 'arguments' => ['target' => 'private'], 'approval_reason' => 'Review',
        ]], 'replay_blocks' => []]],
    ]]]);
    app()->instance(ApprovalPolicy::class, new class implements ApprovalPolicy
    {
        public function mayApprove(ChatParticipantInterface $actor, ChatMessage $requester, array $approvals): bool
        {
            return $actor->getKey() === $requester->sender->participant_id;
        }

        public function mayResume(ChatMessage $requester, array $approvals): bool
        {
            return false;
        }
    });
    $batch = app(ApprovalBatches::class)->create($request, $paused);
    $url = '/api/approvals/'.$batch->id;

    $this->actingAs($other)->getJson($url)->assertForbidden();
    $this->actingAs($requester)->getJson($url)->assertOk()->assertJsonPath('approvals.0.arguments.target', 'private');
    Gate::define('le-chat.interact-with-message', fn (AttachmentTestUser $user, ChatMessage $target): bool => true);
    $this->postJson('/api/interactions', [
        'message_id' => $paused->id,
        'block_id' => 'approval-'.$batch->id.'-actions', 'action_id' => 'approve',
    ])->assertUnprocessable();
    $payload = ['idempotency_key' => 'request-1', 'decisions' => ['call-1' => ['action' => 'approve']]];
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson($url, [...$payload, 'idempotency_key' => 'different-request'])->assertConflict();
    $this->getJson(route('messages.show', $paused->id))
        ->assertOk()->assertJsonPath('data.approval.status', 'cancelled')
        ->assertJsonPath('data.blocks.1.state', 'cancelled');
    expect($paused->fresh()->blocks[1])->not->toHaveKey('state');
});

it('authorizes and dispatches generic block interactions', function () {
    $room = Chatroom::create(['name' => 'Interactive messages']);
    $sender = AttachmentTestUser::create();
    $room->addParticipant($sender);
    $message = $sender->sendMessageTo($room, 'Choose', messageConfiguration: ['blocks' => [
        ['schema_version' => 1, 'type' => 'actions', 'block_id' => 'choices', 'elements' => [
            ['type' => 'button', 'action_id' => 'select', 'text' => 'Select'],
        ]],
    ]]);
    $url = '/api/interactions';
    $payload = ['message_id' => $message->id, 'block_id' => 'choices', 'action_id' => 'select', 'value' => ['choice' => 'one']];

    $this->actingAs($sender)->postJson($url, $payload)->assertForbidden();
    Gate::define('le-chat.interact-with-message', fn (AttachmentTestUser $user, ChatMessage $target): bool => true);
    Event::fake([BlockInteracted::class]);
    $this->postJson($url, $payload)->assertNoContent();
    $this->postJson($url, [...$payload, 'action_id' => 'unknown'])->assertUnprocessable();

    Event::assertDispatched(BlockInteracted::class, fn (BlockInteracted $event): bool => $event->message->is($message)
        && $event->actor->is($sender)
        && $event->blockId === 'choices'
        && $event->actionId === 'select'
        && $event->value() === ['choice' => 'one']);
    expect(Schema::hasTable('chat_block_interactions'))->toBeFalse();
});
