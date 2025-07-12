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
     * @todo make This can be ovverriden on the participant level by setting the `can_see_messages_before_joined` property to true.
     */
    'can_see_messages_before_joined' => false,

    /**
     * If true, system messages will be sent to all participants in the channel on channel events such as participants joining, leaving, etc.
     *
     * If false, system messages will not be created automatically.
     */
    'create_system_messages' => true,

    /**
     * The listener for new messages.
     *
     * This is used to broadcast new messages to the participants of the chatroom.
     */
    'new_message_listener' => \Mmedia\LaravelChat\Listeners\SendMessageCreatedNotification::class,
];
