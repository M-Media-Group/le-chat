---
title: Routing
description: Documentation for Le Chat routing.
---

## Introduction
Le Chat provides a powerful set of API routes for managing chatrooms and sending messages. These routes are automatically registered by the package.

## Configuration
Routes are enabled by default. You can customize the middleware and prefix used for the API routes in `config/chat.php`:

```php
'features' => [
    Features::routes([
        'middleware' =>  ['api', 'auth:sanctum'],
        'prefix' => 'api',
    ]),
],
```

## Available Routes
Le Chat provides a set of API routes that you can use to interact with the chat system.

### GET /api/chatrooms
Retrieves a list of all chatrooms that the authenticated user is a participant in or was a participant in previously. This route is useful for displaying a list of chatrooms in your application. It includes the latest_message visible to the user, the participants, and the unread messages count.
```json
[
    {
        "id": 1,
        "name": null,
        "latest_message": {
            "id": 68,
            "message": "Ok, thanks!",
            "chatroom_id": 1,
            "sender_id": 2,
            "created_at": "2025-07-19T17:38:16.000000Z",
            "updated_at": "2025-07-19T17:38:16.000000Z",
            "sender": {
                "id": 2,
                "participant_id": 1,
                "participant_type": "App\\Models\\User",
                "display_name": "Test User",
                "avatar_url": "https://www.gravatar.com/avatar/55502f40dc8b7c769880b10874abc9d0?d=initials",
                "created_at": "2025-07-18T08:43:55.000000Z",
                "deleted_at": null,
                "chatroom_id": 1
            }
        },
        "created_at": "2025-07-18T08:43:55.000000Z",
        "updated_at": "2025-07-18T08:43:55.000000Z",
        "description": null,
        "participants": [
            {
                "id": 1,
                "participant_id": 7,
                "participant_type": "App\\Models\\User",
                "display_name": "Juvenal Hyatt DDS",
                "avatar_url": "https://www.gravatar.com/avatar/5e1e4a49591f7b00b8657807dcb44028?d=initials",
                "created_at": "2025-07-18T08:43:55.000000Z",
                "deleted_at": null,
                "chatroom_id": 1
            },
            {
                "id": 2,
                "participant_id": 1,
                "participant_type": "App\\Models\\User",
                "display_name": "Test User",
                "avatar_url": "https://www.gravatar.com/avatar/55502f40dc8b7c769880b10874abc9d0?d=initials",
                "created_at": "2025-07-18T08:43:55.000000Z",
                "deleted_at": null,
                "chatroom_id": 1
            }
        ],
        "unread_messages_count": 0
    }
]
```

### POST /api/chatrooms
Creates a new chatroom with the authenticated user as a participant.

```json
{
    "name": "New Chatroom",
    "description": "This is a new chatroom for discussion.",
}
```


### GET /api/chatrooms/{chatroom}
Retrieves a specific chatroom by its ID. This route is useful for displaying the details of a chatroom, including its participants and messages.

```json
{
    "id": 1,
    "name": null,
    "description": null,
    "created_at": "2025-07-18T08:43:55.000000Z",
    "updated_at": "2025-07-18T08:43:55.000000Z",
    "participants": [
        ...
    ],
    "messages": [
      ...
    ]
}
```

### PUT|PATCH /api/chatrooms/{chatroom}
Updates the specified chatroom in storage. Only participants that are an 'admin' of the chatroom can update it. The request body should contain the fields you want to update, such as `name` or `description`.

```json
{
    "name": "Updated Chatroom Name",
    "description": "Updated description for the chatroom."
}
```

### POST /api/chatrooms/{chatroom}/mark-as-read
Marks a chatroom as read for the authenticated user.

### POST /api/messages
Sends a new message in a chatroom or to a chat participant. The Chatroom or ChatParticipant must exist, and the authenticated user must be a participant in the chatroom or chat with the participant.

The required parameters are:
- `to_entity_type`: either `chatroom` or `chat_participant`, depending on whether you want to send a message to a chatroom or a specific participant.
- `to_entity_id`: The ID of the chatroom or chat participant to send the message to.
- `message`: The content of the message to be sent.

## Missing routes
Le Chat does not implement routes for adding or removing participants from chatrooms on purpose, as this will highly depend on your application logic and requirements. You can implement these routes in your application as needed.