<?php

namespace Mmedia\LeChat\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Mmedia\LeChat\Events\MessageUpdated;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

/**
 * @internal Posts "Ana has joined the chat." / "Ana has left the chat." and grows that one message
 * ("Ana, Ben, Cara and 17 others left the chat.") while more people join or leave within the window
 * and nobody posts in between.
 */
final class JoinAndLeaveMessages
{
    public static function joined(ChatParticipant $participant): void
    {
        self::post($participant, 'participants_joined', 'joined', $participant->created_at);
    }

    public static function left(ChatParticipant $participant): void
    {
        // Dated at the moment they left, so the person who left still sees it (they see messages up to deleted_at).
        self::post($participant, 'participants_left', 'left', $participant->deleted_at ?? now());
    }

    private static function post(ChatParticipant $participant, string $key, string $verb, CarbonInterface $at): void
    {
        $chatroom = $participant->chatroom;
        $name = (string) $participant->display_name;
        $message = self::recentMessage($chatroom, $key);

        if ($message !== null && self::addTo($message, $key, $verb, $name, $at)) {
            return;
        }

        $chatroom->sendMessage(self::text($verb, [$name], 1), [
            'created_at' => $at,
            'metadata' => [$key => ['names' => [$name], 'count' => 1]],
        ]);
    }

    /**
     * The room's latest message, if it is this kind of message and someone was added to it within the window.
     */
    private static function recentMessage(Chatroom $chatroom, string $key): ?ChatMessage
    {
        $seconds = (int) config('chat.join_and_leave_messages.group_within_seconds', 5);

        if ($seconds <= 0) {
            return null;
        }

        // Ordered to match the (chatroom_id, created_at) index, so this reads one row.
        $latest = $chatroom->messages()->orderByDesc('created_at')->orderByDesc('id')->first();

        if ($latest === null
            || $latest->sender_id !== null
            || ! isset($latest->metadata[$key])
            || $latest->updated_at->lt(now()->subSeconds($seconds))) {
            return null;
        }

        return $latest;
    }

    /**
     * Rewrite the message to include one more person. Returns false if another request changed it first.
     */
    private static function addTo(ChatMessage $message, string $key, string $verb, string $name, CarbonInterface $at): bool
    {
        $people = $message->metadata[$key];
        $people['count']++;

        if (count($people['names']) < self::namesShown()) {
            $people['names'][] = $name;
        }

        $version = $message->version;
        $metadata = $message->metadata->getArrayCopy();
        $metadata[$key] = $people;

        $message->forceFill([
            'message' => self::text($verb, $people['names'], $people['count']),
            'metadata' => $metadata,
            // People see messages from when they joined until when they left. Moving a join message later and
            // keeping a leave message at the earliest leave lets everyone in the group see it.
            'created_at' => $verb === 'joined' ? $at->max($message->created_at) : $at->min($message->created_at),
            'updated_at' => $message->freshTimestamp(),
            'version' => $version + 1,
        ]);

        // Only update if nobody else did since we read it, so two people at once can't overwrite each other.
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
    private static function text(string $verb, array $names, int $count): string
    {
        if ($count === 1) {
            return "{$names[0]} has {$verb} the chat.";
        }

        $others = $count - count($names);

        if ($others > 0) {
            return implode(', ', $names)." and {$others} ".Str::plural('other', $others)." {$verb} the chat.";
        }

        $last = array_pop($names);

        return implode(', ', $names)." and {$last} {$verb} the chat.";
    }

    private static function namesShown(): int
    {
        return max(1, (int) config('chat.join_and_leave_messages.names_shown', 3));
    }
}
