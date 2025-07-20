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