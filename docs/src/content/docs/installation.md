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
php artisan vendor:publish --tag="chat-migrations"
php artisan migrate
```

That's it! Le Chat is now installed and ready to use. You can [optionally publish the config](/package-configuration).

### Bleeding-edge version
:::danger
Le Chat is in active beta and breaking changes are likely to occur!
:::
If you want to use the latest features and fixes, you can install the bleeding-edge version directly from the GitHub repository. Before running the `require`, add the following to your composer.json:
```json

 "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:M-Media-Group/le-chat.git"
        }
    ],
```