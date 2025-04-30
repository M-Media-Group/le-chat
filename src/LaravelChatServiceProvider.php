<?php

namespace MMedia\LaravelChat;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use MMedia\LaravelChat\Commands\LaravelChatCommand;

class LaravelChatServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-chat')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_chat_table')
            ->hasCommand(LaravelChatCommand::class);
    }
}
