---
title: Configuration
description: Documentation for installing Le Chat.
---

## Configuration

Le Chat comes with sensible defaults, but you can customize its behavior to fit your application's needs.

First, publish the configuration file using the following Artisan command:
```bash
php artisan vendor:publish --tag="le-chat-config"
```

This will create a `config/chat.php` file where you can customize the package settings. All of the configuration options are documented in the file itself.

## Feature configuration
Le Chat uses the `Features` class to enable or disable various features. You can customize the features in the `config/chat.php` file under the `features` key.

```diff lang="php"
// config/chat.php
+use Mmedia\LeChat\Features;

return [

    // Other configuration options...

+    'features' => [
+        // define features here
+    ],
];
```
### Routes
Routes are enabled by default. If you publish the package configuration file, you can customize the middleware and prefix used for the API routes in `config/chat.php`:

```php
// config/chat.php
'features' => [

    // If present, the feature is enabled
    Features::routes([
        // Customize the middleware used for the API routes
        'middleware' => ['api', 'auth:sanctum'],

        // The prefix for the API routes
        'prefix' => 'api',
    ]),
],
```

### Encryption of Messages at Rest
You can enable the encryption of messages at rest by adding the following feature to your configuration:
```php
// config/chat.php
'features' => [
    Features::encryptMessagesAtRest(),
],
```


#### Allowing failed decryption
When your application is switching from unencrypted to encrypted messages but already has messages in the database, and for some reason you are not yet able to run the Artisan commands to migrate, you can allow failed decryption by setting the `return_failed_decrypt` option to `true` in the feature configuration:
```php
// config/chat.php
'features' => [
    Features::encryptMessagesAtRest([
        'return_failed_decrypt' => true,
    ]),
],
```
This will allow your application to return the original message content if decryption fails, instead of throwing an exception. This is useful for transitioning existing messages to encrypted storage without losing data.

You should NOT rely on this feature as it may be unsafe. Instead, when switching to encrypted messages, you should migrate your existing messages to the encrypted format with the provided commands.