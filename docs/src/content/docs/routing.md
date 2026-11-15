---
title: Routing
description: Documentation for Le Chat routing.
---

## Introduction
Le Chat provides a powerful set of API routes for managing chatrooms and sending messages. These routes are automatically registered by the package.

## Configuration
Routes are enabled by default. You can customize the middleware, URL prefix, and controllers used for the API routes in `config/chat.php`. All route examples below use the default `api` prefix; replace it with your configured prefix (for example, `api/chat`).

```php
use Mmedia\LeChat\Features;

'features' => [
    Features::routes([
        'middleware' =>  ['api', 'auth:sanctum'],
        'prefix' => 'api',
    ]),
],
```

To customize route behavior, set `controller` or `file_controller` to your own controller class and extend the corresponding package controller. The package controllers are used by default.

## Available Routes
Le Chat provides a set of API routes that you can use to interact with the chat system.

### Staged file uploads
These routes are available only when `Features::files()` is enabled.

`POST /api/files` creates a pending upload using `name`, `mime_type`, byte `size`, and a lowercase SHA-256 checksum in `sha256`. Send the bytes to the returned `upload_url` using `upload_method`. For a private S3 disk, submit `upload_fields` and the file as multipart form data; configure your bucket CORS policy for the browser origin. For the server fallback, send the file as `file` to `POST /api/files/{file}/content`. Then call `PATCH /api/files/{file}` to finalize it.

The staged upload flow verifies the stored bytes against the declared size, checksum, and MIME type before marking a file ready. Ready means verified, not malware-scanned. Only its uploader can complete or share it.

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
            "deleted_at": null,
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
    "description": "This is a new chatroom for discussion."
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
Updates the specified chatroom. Only an admin participant can update it. The request must include `name`; `description` is optional.

```json
{
    "name": "Updated Chatroom Name",
    "description": "Updated description for the chatroom."
}
```

### POST /api/chatrooms/{chatroom}/mark-as-read
Marks a chatroom as read for the authenticated user.

### GET /api/messages/{message}
Retrieves a single message, formatted with the `MessageResource`. The message must be visible to the authenticated user. Use this to load the full message when a [broadcasted payload was trimmed](/broadcasting#broadcasted-data).

### POST /api/messages
Sends a new message in a chatroom or to a chat participant. The Chatroom or ChatParticipant must exist, and the authenticated user must be a participant in the chatroom or chat with the participant.

The required parameters are:
- `to_entity_type`: `chatroom` or `chat_participant`.
- `to_entity_id`: The ID of the target chatroom or participant.
- `message`: Text to send. It may be empty or omitted when you send `blocks`, or when files are enabled and you send `files`.

Optional parameters:
- `blocks`: A list of message blocks. See [Message blocks](/messages#message-blocks) for the supported format.
- `files`: A list of ready file IDs owned by the sender. Available only when `Features::files()` is enabled; upload files first as described below.

Example request:

```json
{
    "to_entity_type": "chatroom",
    "to_entity_id": 11,
    "message": "Hello",
    "files": [42]
}
```

The route returns the created message with a `201` status code. File entries contain `id`, `name`, `mime_type`, `size`, and an authenticated download `url`; storage paths are not returned.

### PATCH /api/messages/{message}
Edits a message with its current `version` and a `message` or `blocks`. The authenticated user must be an active participant in the message's chatroom, and the `le-chat.update-message` gate must authorize the edit. A stale version returns 409.

### GET /api/files/{file}
Downloads a ready file only when the authenticated participant can see one of its non-deleted message shares. Files are streamed as downloads, not inline content.

### Approval decisions
`GET /api/approvals/{batch}` returns pending tool details only to an authorized approver. POST to the same URL with an `idempotency_key` and a `decisions` object keyed by every pending tool call ID. Each decision has an `action` of `approve` or `reject`; a rejection can include a `result` for the AI agent. Every pending call needs a decision.

```json
{
    "idempotency_key": "decision-attempt-1",
    "decisions": {
        "call_abc": { "action": "approve" },
        "call_def": { "action": "reject", "result": "The user declined this change." }
    }
}
```

Repeating the same request returns the recorded status; a conflicting decision returns 409. If the conversation is busy, the response remains `pending`; retry with the same idempotency key.

### Block interactions
`POST /api/interactions` accepts `message_id`, `block_id`, `action_id`, and optional JSON `value`. The active participant must be authorized by the application-defined `le-chat.interact-with-message` gate. The endpoint dispatches `BlockInteracted` after the request transaction commits; the application handles the action. Approval buttons use the approval decision route instead. A successful interaction returns `204 No Content`.

## Missing routes
Le Chat does not implement routes for adding or removing participants from chatrooms on purpose, as this will highly depend on your application logic and requirements. You can implement these routes in your application as needed.