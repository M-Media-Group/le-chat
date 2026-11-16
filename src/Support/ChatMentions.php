<?php

namespace Mmedia\LeChat\Support;

use Illuminate\Validation\ValidationException;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\Chatroom;

class ChatMentions
{
    /** @return list<string> */
    public static function participantIds(string $text): array
    {
        preg_match_all('/<@([1-9][0-9]*)>/', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    public static function text(ChatMessage $message): string
    {
        $texts = [$message->message];
        foreach ($message->blocks ?? [] as $block) {
            if ($block['type'] === 'section') {
                $texts[] = $block['text'];
            }
        }

        return implode("\n", $texts);
    }

    public static function validate(ChatMessage $message): void
    {
        $ids = self::participantIds(self::text($message));
        if ($ids !== [] && $message->chatroom->participants()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['message' => 'Mentions must reference active participants in this chatroom.']);
        }
    }

    /** @return array{targeted: bool, participant_ids: list<int>} */
    public static function resolve(Chatroom $room, string $text): array
    {
        $ids = self::participantIds($text);
        $targeted = $ids !== [];
        $participants = $room->relationLoaded('participants') ? $room->participants : $room->participants()->get();
        $resolved = [];
        $aliases = [];
        foreach ($participants as $participant) {
            $participant->setRelation('chatroom', $room);
            if (in_array((string) $participant->getKey(), $ids, true)) {
                $resolved[] = $participant->getKey();
            }
            foreach ($participant->mentionAliases() as $alias) {
                $aliases[$alias][] = $participant->getKey();
            }
        }
        foreach ($aliases as $alias => $participantIds) {
            if (preg_match('/(?<![\p{L}\p{N}_@])@'.preg_quote($alias, '/').'(?![\p{L}\p{N}_-])/iu', $text) === 1) {
                $targeted = true;
                if (count($participantIds) === 1) {
                    $resolved[] = $participantIds[0];
                }
            }
        }

        return ['targeted' => $targeted, 'participant_ids' => array_values(array_unique($resolved))];
    }
}
