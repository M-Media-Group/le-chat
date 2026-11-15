<?php

// config for Mmedia/LeChat

use Mmedia\LeChat\Features;
use Mmedia\LeChat\Listeners\SendMessageCreatedNotification;
use Mmedia\LeChat\Listeners\SendParticipantCreatedNotification;
use Mmedia\LeChat\Listeners\SendParticipantDeletedNotification;

return [
    'blocks' => [
        'max_blocks' => 50,
        'max_text_length' => 3000,
        'max_payload_bytes' => 65536,
    ],

    'approvals' => [
        'ttl_minutes' => 15,
    ],

    /**
     * Determines if, when looking for a best channel, the order we should apply. If true, if more than one channel matches, the latest updated channel will be used. If false, the first channel that matches will be used.
     *
     * @var bool
     */
    'latest_channel' => true,

    /**
     * Whether participants can see messages and their files sent before they joined.
     *
     * If false, only messages sent at or after joining are visible. Messages sent
     * after leaving remain hidden regardless of this setting.
     *
     * PHP visibility queries may override this setting using includeBeforeJoined.
     */
    'can_see_messages_before_joined' => false,

    /**
     * If true, system messages will be sent to all participants in the channel on channel events such as participants joining, leaving, etc.
     *
     * If false, system messages will not be created automatically.
     */
    'create_system_messages' => true,

    /**
     * Mark all previous messages as read for a given participant when that participant sends a message.
     *
     * LeChat uses a timestamp per channel-participant to determine if a message was sent after the participant joined the channel.
     */
    'update_sender_read_at_on_message_created' => true,

    /**
     * The listener for new messages.
     *
     * This is used to broadcast new messages to the participants of the chatroom.
     *
     * Set to null to disable the listener.
     */
    'new_message_listener' => SendMessageCreatedNotification::class,

    /**
     * The listener for new participants.
     *
     * This is used to broadcast new participants to the participants of the chatroom.
     *
     * Set to null to disable the listener.
     */
    'new_participant_listener' => SendParticipantCreatedNotification::class,

    /**
     * The listener for deleted participants.
     *
     * This is used to broadcast deleted participants to the participants of the chatroom.
     *
     * Set to null to disable the listener.
     */
    'participant_deleted_listener' => SendParticipantDeletedNotification::class,

    'features' => [
        Features::routes(config('chat-options.routes', [
            'middleware' => ['api', 'auth:sanctum'],
            'prefix' => 'api',
        ])),
        Features::aiSdkIntegration([
            'auto_respond' => true,
            'typing_indicators' => true,
            'collapse_tool_history' => [],
            'prompt_config' => [],
        ]),
    ],
];
