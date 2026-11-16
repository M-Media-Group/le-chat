---
title: Configuration
description: Configure Le Chat features and scheduled tasks.
---

## Introduction

Le Chat comes with sensible defaults, but you can customize its behavior to fit your application's needs.

First, publish the configuration file using the following Artisan command:
```bash
php artisan vendor:publish --tag="le-chat-config"
```

This creates `config/chat.php`. The file contains the defaults; this page explains what the settings do and when to change them.

## Scheduled tasks

Le Chat registers maintenance tasks with Laravel's scheduler when their related features are enabled:

- File cleanup runs every ten minutes. It reconciles deleted message shares and removes expired, unshared files.
- Approval expiry runs every minute when AI SDK integration is enabled.
- Daily unread notifications run at the configured time when that feature is enabled.

Your application must run Laravel's scheduler. Use `php artisan schedule:work` locally or configure Laravel's standard `schedule:run` cron entry in production. Le Chat does not need separate cron entries. Remove manual schedules for these same tasks to avoid running them twice. If the scheduler runs on multiple servers, use a shared cache store that supports atomic locks.

## Message blocks

The default `chat.blocks` limits are 50 blocks, 3,000 characters per text field, and 65,536 bytes per encoded payload. These limits can be configured with `max_blocks`, `max_text_length`, and `max_payload_bytes`.

## Features

Add features to the `features` array in `config/chat.php`. Keep existing entries when adding a feature; the examples below show only the relevant part of the config file.

### Files

Files are disabled unless `Features::files()` is included in the `features` array:

```php
use Mmedia\LeChat\Features;

'features' => [
    Features::files([
        'disk' => null, // inherit the application's default filesystem disk
        'path' => 'chat-files',
        'max_files' => 10,
        'max_size' => 10240, // kilobytes per file
        'allowed_mime_types' => [], // empty allows any type
        'pending_ttl_hours' => 1,
        'unshared_ttl_hours' => 24,
        'max_pending_uploads' => 10,
        'max_unshared_files' => 100,
    ]),
],
```

These are the default values; omit options you do not need to change. File routes and cleanup are enabled only when this feature is present. Message blocks do not require the files feature. If files are disabled and later re-enabled, cleanup reconciles shares from deleted messages.

Omitting `disk` or setting it to `null` uses the consuming application's `filesystems.default` disk (normally selected through `FILESYSTEM_DISK`). Set `disk` to a disk name to override that default for chat files. Disks are resolved through Laravel's `Storage` manager, using the application's drivers, roots, credentials, endpoints, and other disk settings.

Configure the disk and its underlying storage to be private. Le Chat rejects a resolved disk explicitly configured with public visibility, but cannot verify bucket policies or web-server configuration. App-mediated uploads explicitly use private visibility. The selected disk is recorded for each file, so uploads, downloads, and pruning continue using that disk even if the application's default or the feature's disk option changes. Existing files are not migrated. Application and server upload limits may impose lower limits. File owners must be Eloquent models on the chat database connection so per-owner quotas can be locked safely.

After upgrading an existing installation, publish the package migrations again and run `php artisan migrate` to create the file and block tables. Keep existing published migrations; do not overwrite or rerun them.

### Routes

Routes are enabled by default. Customize the middleware, URL prefix, and controllers in `config/chat.php`:

```php
use Mmedia\LeChat\Features;

'features' => [
    Features::routes([
        'middleware' => ['api', 'auth:sanctum'],
        'prefix' => 'api',
        'controller' => App\Http\Controllers\ChatroomController::class,
        'file_controller' => App\Http\Controllers\ChatFileController::class,
    ]),
],
```

See [Routing](/routing#configuration) for details about extending the controllers.

### Encrypting messages at rest

Enable message encryption by adding this feature:
```php
'features' => [
    Features::encryptMessagesAtRest(),
],
```


If the database contains unencrypted messages during a migration, `return_failed_decrypt` can temporarily return their original text instead of throwing an exception:
```php
'features' => [
    Features::encryptMessagesAtRest([
        'return_failed_decrypt' => true,
    ]),
],
```
Use this only as a short-lived migration aid. Run the provided encryption command to migrate existing messages; do not leave failed-decryption fallback enabled as a permanent setting.

### Laravel AI SDK integration

The package config enables this integration by default. It takes effect when `laravel/ai` is installed. Remove the feature entry to disable the integration. The options below are the defaults:

```php
use Mmedia\LeChat\Features;

'features' => [
    Features::aiSdkIntegration([
        'auto_respond' => true,
        'typing_indicators' => true,
        'collapse_tool_history' => [],
        'prompt_config' => [],
    ]),
],
```

`auto_respond` is the global switch for automatic AI replies. Set it to `false` if your application will prompt agents itself; a participant's response mode cannot override it. Leave it `true` and use `$agentParticipant->setAiResponseMode('mentions')` on the participant returned by `$chatroom->addAgent(YourAgent::class)` to choose when it responds. The available modes are `all`, `mentions`, and `off`. See [Choose when an agent responds](/ai-sdk#choose-when-an-agent-responds) for a step-by-step guide, including human handoff and avoiding unnecessary model requests.

`typing_indicators` controls whether the agent participant broadcasts typing status while it works. `prompt_config` is appended to the arguments passed to the Laravel AI SDK's `prompt()` method; use only options supported by your agent and SDK version.

`collapse_tool_history` is an empty list by default, so older tool calls remain in the conversation context sent to the agent. Add agent class names to replay older, completed turns from those agents as plain text instead of including their full tool-call history. Replace the empty list in the AI SDK configuration above with:

```php
'collapse_tool_history' => [
    App\Ai\Agents\ManagerSupportAgent::class,
],
```

Le Chat applies this option while automatically responding, when it knows which agent is active. Direct Laravel AI SDK prompts do not set that agent context, so their history is not collapsed. Collapsing can reduce the context the model must process in long conversations, which may reduce latency and usage. The tradeoff is that the agent cannot inspect exact tool calls or results from older turns and may need to call a tool again. The newest assistant turn and turns waiting for approval always retain their tool calls. This only changes history sent to the model; it does not delete stored history or control what the chat API returns. If an older completed turn has no assistant text, it is omitted from replay because there is no plain-text response to send.

### Daily unread notifications

This feature is disabled by default. Add it to the same `features` array to schedule unread-message notifications:

```php
use Mmedia\LeChat\Features;

'features' => [
    Features::dailyUnreadNotifications([
        'at' => '09:00',
        'days' => 1,
    ]),
],
```

`at` is the daily time in 24-hour `HH:MM` format. `days` is the look-back window passed to `le-chat:notify-users-of-recent-unread-messages`; it defaults to one day. The command requires `App\Models\User` to implement `ChatParticipantInterface`. See [Commands](/commands#le-chat-notify-users-of-recent-unread-messages).