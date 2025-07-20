---
title: Installation
description: Documentation for installing Le Chat.
---

## Installation
Le Chat can be installed using Composer. First, ensure you meet the requirements for the package:
- PHP 8.1 or higher
- Laravel 11

Run the following command to install the package:
```bash
composer require mmedia/le-chat
```

Then, publish the migrations and run the migrations to set up the necessary database tables:

```bash
php artisan vendor:publish --tag="chat-migrations"
php artisan migrate
```

That's it! Le Chat is now installed and ready to use.

### Bleeding-edge version
If you want to use the latest features and fixes, you can install the bleeding-edge version directly from the GitHub repository. Before running the `require`, add the following to your composer.json:
```json

 "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:M-Media-Group/le-chat.git"
        }
    ],
```