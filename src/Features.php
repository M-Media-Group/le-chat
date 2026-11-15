<?php

namespace Mmedia\LeChat;

/** Configure and inspect Le Chat package features. */
class Features
{
    public static function enabled(string $feature): bool
    {
        return in_array($feature, config('chat.features', []), true);
    }

    public static function optionEnabled(string $feature, string $option): bool
    {
        return static::enabled($feature) &&
            config("chat-options.{$feature}.{$option}") === true;
    }

    /** Enable Le Chat API routes and optionally configure their middleware, prefix, and controllers. */
    public static function routes(array $options = []): string
    {
        if (! empty($options)) {
            config(['chat-options.routes' => $options]);
        }

        return 'routes';
    }

    /** Enable shared file uploads and downloads. */
    public static function files(array $options = []): string
    {
        if (! empty($options)) {
            config(['chat-options.files' => $options]);
        }

        return 'files';
    }

    /** Schedule daily notifications for recent unread messages. */
    public static function dailyUnreadNotifications(array $options = []): string
    {
        if (! empty($options)) {
            config(['chat-options.daily-unread-notifications' => $options]);
        }

        return 'daily-unread-notifications';
    }

    /** Enable encryption of message text and blocks at rest. */
    public static function encryptMessagesAtRest(array $options = []): string
    {
        if (! empty($options)) {
            config(['chat-options.encrypt-messages-at-rest' => $options]);
        }

        // This feature does not require any specific options, so we return a simple string.
        return 'encrypt-messages-at-rest';
    }

    /** Enable integration with the Laravel AI SDK. */
    public static function aiSdkIntegration(array $options = []): string
    {
        if (! empty($options)) {
            config(['chat-options.ai-sdk-integration' => $options]);
        }

        return 'ai-sdk-integration';
    }
}
