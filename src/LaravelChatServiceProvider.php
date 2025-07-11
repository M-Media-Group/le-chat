<?php

namespace Mmedia\LaravelChat;

use Mmedia\LaravelChat\Commands\LaravelChatCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
            ->discoversMigrations()
            ->hasRoutes('channels', 'api')
            ->hasCommand(LaravelChatCommand::class);
    }
}
