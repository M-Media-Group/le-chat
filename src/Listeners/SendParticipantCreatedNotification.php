<?php

namespace Mmedia\LeChat\Listeners;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\Chatroom;

/**
 * Posts "Ana has joined the chat." and grows that one message ("Ana, Ben, Cara and 17 others joined the chat.")
 * while more people join within the configured window and nobody posts in between.
 */
class SendParticipantCreatedNotification
{
    public function handle(ParticipantCreated $event): void
    {
        if (! config('chat.create_system_messages', true)) {
            return;
        }

        $participant = $event->participant;
        $chatroom = $participant->chatroom;
        $name = (string) $participant->display_name;
        $joinedAt = $participant->created_at;

        $message = $this->recentJoinMessage($chatroom);

        if ($message !== null && $this->addToJoinMessage($message, $name, $joinedAt)) {
            return;
        }

        $chatroom->sendMessage($this->text([$name], 1), [
            'created_at' => $joinedAt,
            'metadata' => ['participants_joined' => ['names' => [$name], 'count' => 1]],
        ]);
    }

    /**
     * The room's latest message, if it is a join message posted within the window.
     */
    private function recentJoinMessage(Chatroom $chatroom): ?ChatMessage
    {
        $seconds = (int) config('chat.join_messages.group_within_seconds', 5);

        if ($seconds <= 0) {
            return null;
        }

        // Ordered to match the (chatroom_id, created_at) index and bounded to the window, so this reads a few rows at most.
        $latest = $chatroom->messages()
            ->where('created_at', '>=', now()->subSeconds($seconds))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null || $latest->sender_id !== null || ! isset($latest->metadata['participants_joined'])) {
            return null;
        }

        return $latest;
    }

    /**
     * Rewrite the join message to include one more person. Returns false if another request changed it first.
     */
    private function addToJoinMessage(ChatMessage $message, string $name, Carbon $joinedAt): bool
    {
        $joined = $message->metadata['participants_joined'];
        $joined['count']++;

        if (count($joined['names']) < $this->namesShown()) {
            $joined['names'][] = $name;
        }

        $version = $message->version;
        $metadata = $message->metadata->getArrayCopy();
        $metadata['participants_joined'] = $joined;

        $message->forceFill([
            'message' => $this->text($joined['names'], $joined['count']),
            'metadata' => $metadata,
            // Moving the time to the latest join lets everyone in the group see the message.
            'created_at' => $joinedAt->max($message->created_at),
            'updated_at' => $message->freshTimestamp(),
            'version' => $version + 1,
        ]);

        // Only update if nobody else did since we read it, so two joins at once can't overwrite each other.
        $updated = $message->newQuery()->toBase()
            ->where('id', $message->getKey())
            ->where('version', $version)
            ->update(array_intersect_key($message->getAttributes(), array_flip(['message', 'metadata', 'created_at', 'updated_at', 'version'])));

        if ($updated === 0) {
            return false;
        }

        $message->syncOriginal();
        $message->getConnection()->afterCommit(fn () => MessageUpdated::dispatch($message));

        return true;
    }

    /**
     * @param  list<string>  $names
     */
    private function text(array $names, int $count): string
    {
        if ($count === 1) {
            return "{$names[0]} has joined the chat.";
        }

        $others = $count - count($names);

        if ($others > 0) {
            return implode(', ', $names)." and {$others} ".Str::plural('other', $others).' joined the chat.';
        }

        $last = array_pop($names);

        return implode(', ', $names)." and {$last} joined the chat.";
    }

    private function namesShown(): int
    {
        return max(1, (int) config('chat.join_messages.names_shown', 3));
    }
}
