<?php

namespace Mmedia\LeChat\Support;

use Illuminate\Http\Request;
use Mmedia\LeChat\Http\Resources\MessageResource;
use Mmedia\LeChat\Models\ChatMessage;

/** @internal Keeps chat broadcasts within provider payload limits. */
class BroadcastPayload
{
    /** 10KB broadcaster limit minus headroom for the event envelope and JSON re-escaping. */
    public const MAX_BYTES = 9000;

    /**
     * Build a message payload that fits the broadcaster limit.
     *
     * @return array<string, mixed>
     */
    public static function forMessage(ChatMessage $message): array
    {
        return self::fit(MessageResource::make($message)->resolve(new Request));
    }

    /**
     * Drop heavy fields (metadata first, then message) until the payload fits.
     * The frontend can fetch whatever was dropped through the API.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fit(array $payload): array
    {
        foreach (['metadata', 'files', 'blocks', 'message'] as $key) {
            if (self::size($payload) <= self::MAX_BYTES) {
                break;
            }

            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function size(array $payload): int
    {
        return strlen((string) json_encode($payload));
    }
}
