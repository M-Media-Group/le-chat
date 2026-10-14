<?php

namespace Mmedia\LeChat\Ai;

/**
 * Self-describing wrapper that tells the model who is speaking, without the agent knowing the format.
 *
 * Only the message being answered is a <requester>; replayed history is <earlier>, so "me" can only
 * mean the current sender (the actor the tools authorise against).
 */
final class RequesterEnvelope
{
    private const string OWN_TAGS = '#</?(?:requester|request|earlier)\b[^>]*>#i';

    /** The message being answered now. Use it at the prompt() call site. */
    public static function current(string $requester, string $text): string
    {
        return '<requester>'.self::name($requester).'</requester><request>'.self::strip($text).'</request>';
    }

    /** A message from earlier in the room; never the requester of this turn. */
    public static function earlier(string $sender, string $text): string
    {
        return '<earlier from="'.self::name($sender).'">'.self::strip($text).'</earlier>';
    }

    /** Recover the raw text from a current() envelope; anything else is returned untouched. */
    public static function unwrap(string $text): string
    {
        return preg_match('#^<requester>.*?</requester><request>(.*)</request>$#s', $text, $matches) === 1
            ? $matches[1]
            : $text;
    }

    /** Remove our own delimiters from user text instead of entity-encoding it, which would mangle & and <. */
    private static function strip(string $text): string
    {
        return preg_replace(self::OWN_TAGS, '', $text) ?? $text;
    }

    /** Names are removed of characters that could break out of a tag or attribute (not encoded: O'Brien stays O'Brien). */
    private static function name(string $name): string
    {
        return trim(str_replace(['<', '>', '"'], '', $name)) ?: 'unknown';
    }
}
