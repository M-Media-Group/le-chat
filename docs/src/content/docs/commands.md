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

This is a useful command to run periodically, for example, via a cron job, to ensure that users are notified of any unread messages they may have missed.

Optional arguments:
- `--days`: The number of days to look back for unread messages. Default is today (0 days).

### Example usage in `routes/console.php`
You can schedule this command to run periodically in your `routes/console.php` file:
```php
use Mmedia\LeChat\Commands\NotifyUsersOfRecentUnreadMessages;

Schedule::command(NotifyUsersOfRecentUnreadMessages::class, ['--days' => 1])
    ->dailyAt('09:00')
    ->description('Notify users of recent unread messages from the last day');
```

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