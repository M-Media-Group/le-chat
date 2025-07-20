---
title: Installation
description: Documentation for installing Laravel Chat.
---

## Installation
Laravel Chat can be installed via Composer. First, ensure you meet the requirements for the package:
- PHP 8.1 or higher
- Laravel 11

```bash
composer require mmedia/laravel-chat
```

Then, publish the migrations and run the migrations to set up the necessary database tables:

```bash
php artisan vendor:publish --tag="chat-migrations"
php artisan migrate
```

That's it! Laravel Chat is now installed and ready to use.