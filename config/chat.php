<?php

// config for Mmedia/LaravelChat

use Mmedia\LaravelChat\Models\Chatroom;

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
     *
     * Set to null to disable the listener.
     */
    'new_message_listener' => \Mmedia\LaravelChat\Listeners\SendMessageCreatedNotification::class,

    /**
     * The listener for new participants.
     *
     * This is used to broadcast new participants to the participants of the chatroom.
     *
     * Set to null to disable the listener.
     */
    'new_participant_listener' => \Mmedia\LaravelChat\Listeners\SendParticipantCreatedNotification::class,

    /**
     * The listener for deleted participants.
     *
     * This is used to broadcast deleted participants to the participants of the chatroom.
     *
     * Set to null to disable the listener.
     */
    'participant_deleted_listener' => \Mmedia\LaravelChat\Listeners\SendParticipantDeletedNotification::class,

    /**
     * The default channel to use for broadcast auth
     *
     * This should stay as much as possible as it is - we define it here so we can leverage route caching
     */
    'default_broadcast_channel' => (new Chatroom)->broadcastChannelRoute(),
];
