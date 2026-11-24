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
     * Default history access copied to newly created chatrooms.
     *
     * Each room copies its value to new participant memberships. Changing this
     * config does not affect existing rooms or memberships. Message visibility
     * uses the membership's stored value; post-leave messages remain hidden.
     *
     * PHP visibility queries may override the membership's stored value for that
     * query using includeBeforeJoined, without changing any stored setting.
     */
    'can_see_messages_before_joined' => false,

    /**
     * If true, Le Chat posts "Ana has joined the chat." and "Ana has left the chat." when people join or leave.
     *
     * If false, it posts neither. Participant events still fire, and messages you send yourself are unaffected.
     */
    'create_system_messages' => true,

    /**
     * People who join (or leave) close together share one message: "Ana, Ben, Cara and 17 others joined the chat."
     *
     * A join or leave is added to the room's latest join or leave message if it comes within `group_within_seconds`
     * of the previous one and nobody has posted since. Set `group_within_seconds` to 0 to post one message per person.
     */
    'join_and_leave_messages' => [
        'group_within_seconds' => 5,
        'names_shown' => 3,
    ],

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
