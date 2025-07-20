<?php

namespace Mmedia\LeChat;

/**
 * Taken shamelessly from Laravel Fortify's Features class.
 *
 * This class is used to determine if certain features of the chat application are enabled.
 *
 * Usage:
 * - Check if a feature is enabled: Features::enabled(Features::routes());
 * - Check if a feature is enabled with a specific option: Features::optionEnabled(Features::routes(), 'some_option');
 */
class Features
{
    /**
     * Determine if the given feature is enabled.
     *
     * @return bool
     */
    public static function enabled(string $feature)
    {
        return in_array($feature, config('chat.features', []));
    }

    /**
     * Determine if the feature is enabled and has a given option enabled.
     *
     * @return bool
     */
    public static function optionEnabled(string $feature, string $option)
    {
        return static::enabled($feature) &&
            config("chat-options.{$feature}.{$option}") === true;
    }

    /**
     * Enable the registration feature.
     *
     * @return string
     */
    public static function routes(array $options = [])
    {
        if (! empty($options)) {
            config(['chat-options.routes' => $options]);
        }

        return 'routes';
    }
}
