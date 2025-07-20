---
title: Installation
description: Documentation for installing Le Chat.
---

## Installation
Le Chat can be installed using Composer. First, ensure you meet the requirements for the package:
- PHP 8.1 or higher
- Laravel 11

A Composer package is coming soon, but for now, you can install it directly from the Git repository.

First, add the VCS repository to your `composer.json` file:
```json
 "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:M-Media-Group/laravel-chat.git"
        }
    ],
```

Then, run the following command to install the package:
```bash
composer require mmedia/laravel-chat
```

Then, publish the migrations and run the migrations to set up the necessary database tables:

```bash
php artisan vendor:publish --tag="chat-migrations"
php artisan migrate
```

That's it! Le Chat is now installed and ready to use.