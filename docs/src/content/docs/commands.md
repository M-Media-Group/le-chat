---
title: Commands
description: Documentation for Le Chat commands.
---

## Introduction
:::note
The provided chat commands assume your `\App\Models\User` model is configured as a participant in Le Chat.
:::

Le Chat provides Laravel Commands to make your life easier.

## `le-chat:send-message`
Useful for debugging and testing, this command lets you send a message from a user, to a user, using the command line.

The required arguments are positional:
- `fromUserId`: The ID of the user sending the message.
- `message`: The content of the message to be sent.
- `toUserId`: The ID of the user receiving the message.

## `le-chat:notify-users-of-recent-unread-messages`
This command is used to notify users of recent unread messages in their chatrooms. It will send notifications to all participants in the chatrooms where they have unread messages.

To send these notifications automatically, enable the optional daily notification feature. It is disabled by default, so installing the package does not start sending notifications.

Optional arguments:
- `--days`: The number of days to include in the look-back window. The default is `0`, which checks today.

### Automatic notifications
Add this feature to the existing `features` array in `config/chat.php`:

```php
use Mmedia\LeChat\Features;

return [
    'features' => [
        Features::dailyUnreadNotifications([
            'at' => '09:00',
            'days' => 1,
        ]),
    ],
];
```

The options default to `09:00` and one day. The command still requires `App\Models\User` to implement `ChatParticipantInterface`. Your application must run Laravel's scheduler; see [Configuration](/package-configuration).

## `le-chat:encrypt-messages`
This command is used to encrypt all chat messages in the database. It will attempt to encrypt each message, and if the message is already encrypted, it will skip it.
This is useful for securing chat messages at rest.
### Example usage
```bash
php artisan le-chat:encrypt-messages
```

## `le-chat:decrypt-messages`
This command is used to decrypt all chat messages in the database. It will attempt to decrypt each message, and if the message is not encrypted, it will skip it.
This is useful for reverting the encryption of chat messages if needed.
### Example usage
```bash
php artisan le-chat:decrypt-messages
```

## `le-chat:delete-messages`
:::danger
This command is not reversible.
:::
This command is used to delete all chat messages by setting the `message` attribute to `null` on each message. This effectively removes the content of the messages while keeping the metadata intact, such as sender, timestamps, etc.
### Example usage
```bash
php artisan le-chat:delete-messages
```