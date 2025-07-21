---
title: Installation
description: Documentation for installing Le Chat.
---

## Prerequisites
Before installing Le Chat, ensure you have the following prerequisites:
- Laravel 11 or higher installed
- PHP 8.1 or higher
- Composer installed

## Installation
Le Chat is available on Composer. Run the following command to install the package:
```bash
composer require mmedia/le-chat
```

Then, publish the migrations and run the migrations to set up the necessary database tables:

```bash
php artisan vendor:publish --tag="le-chat-migrations"
php artisan migrate
```

Finally, publish the configuration file to customize the package settings:
```bash
php artisan vendor:publish --tag="le-chat-config"
```

That's it! Le Chat is now installed and ready to use. You can [optionally customise the config](/package-configuration) if you want.
