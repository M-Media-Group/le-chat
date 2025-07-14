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

    // The boot
    public function bootingPackage(): void
    {
        $newMessageListener = config('chat.new_message_listener');

        if ($newMessageListener && class_exists($newMessageListener)) {
            $this->app['events']->listen(
                \Mmedia\LaravelChat\Events\MessageCreated::class,
                $newMessageListener
            );
        }

        $newParticipantListener = config('chat.new_participant_listener');

        if ($newParticipantListener && class_exists($newParticipantListener)) {
            $this->app['events']->listen(
                \Mmedia\LaravelChat\Events\ParticipantCreated::class,
                $newParticipantListener
            );
        }

        $participantDeletedListener = config('chat.participant_deleted_listener');

        if ($participantDeletedListener && class_exists($participantDeletedListener)) {
            $this->app['events']->listen(
                \Mmedia\LaravelChat\Events\ParticipantDeleted::class,
                $participantDeletedListener
            );
        }

        if (config('chat.update_sender_read_at_on_message_created', true)) {
            $this->app['events']->listen(
                \Mmedia\LaravelChat\Events\MessageCreated::class,
                \Mmedia\LaravelChat\Listeners\UpdatedChatParticipantReadAtOnMessageCreated::class
            );
        }
    }
}
