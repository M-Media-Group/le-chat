<?php

// config for Mmedia/LaravelChat
return [
    /**
     * Determines if, when looking for a best channel, the order we should apply. If true, if more than one channel matches, the latest updated channel will be used. If false, the first channel that matches will be used.
     *
     * @var bool
     */
    'latest_channel' => true,

    /**
     * Don't allow participants by default to see messages on a channel that were sent prior to when they joined.
     *
     * If true, users that join a channel will not see messages that were sent before they joined.
     *
     * This can be ovverriden on the participant level by setting the `can_see_messages_before_joined` property to true.
     */
    'can_see_messages_before_joined' => false,
];
