<?php

namespace Mmedia\LeChat\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Models\ChatFile;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;

/** @internal Associates ready files with messages. */
class MessageFiles
{
    public static function send(ChatMessage $message, array $fileIds): ChatMessage
    {
        if (! Features::enabled(Features::files())) {
            throw new \LogicException('The files feature is disabled.');
        }
        Validator::make(['files' => $fileIds], [
            'files' => ['required', 'array', 'min:1', 'max:'.config('chat-options.files.max_files', 10)],
            'files.*' => ['required', 'integer', 'distinct'],
        ])->validate();
        if (! array_is_list($fileIds)) {
            throw ValidationException::withMessages(['files' => 'Files must be a list.']);
        }
        $sender = $message->sender_id === null ? null : ChatParticipant::withTrashed()->findOrFail($message->sender_id);
        $owner = $sender === null ? $message->chatroom : ($sender->participatingModel ?? $sender);

        DB::transaction(function () use ($message, $fileIds, $owner): void {
            $files = ChatFile::query()->whereIn('id', $fileIds)
                ->where('owner_type', $owner?->getMorphClass())
                ->where('owner_id', $owner?->getKey())
                ->where('status', 'ready')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->lockForUpdate()->get()->keyBy('id');
            if ($files->count() !== count($fileIds)) {
                throw ValidationException::withMessages(['files' => 'Files must be ready and owned by the message sender or chatroom.']);
            }

            $message->deferCreatedEvent();
            $message->save();
            foreach ($fileIds as $position => $fileId) {
                $message->files()->attach($fileId, ['position' => $position]);
            }
            ChatFile::query()->whereIn('id', $fileIds)->update(['expires_at' => null]);
        });

        $message->load('files');
        $message->getConnection()->afterCommit(fn () => MessageCreated::dispatch($message));

        return $message;
    }
}
