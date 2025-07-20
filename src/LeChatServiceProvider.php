<?php

namespace Mmedia\LeChat;

use Mmedia\LeChat\Commands\LeChatCommand;
use Mmedia\LeChat\Commands\NotifyUsersOfRecentUnreadMessages;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LeChatServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('le-chat')
            ->hasConfigFile()
            ->hasViews()
            ->discoversMigrations()
            ->hasRoutes('channels', 'api')
            ->hasCommand(LeChatCommand::class)
            ->hasCommand(NotifyUsersOfRecentUnreadMessages::class);
    }

    // The boot
    public function bootingPackage(): void
    {
        $newMessageListener = config('chat.new_message_listener');

        if ($newMessageListener && class_exists($newMessageListener)) {
            $this->app['events']->listen(
                \Mmedia\LeChat\Events\MessageCreated::class,
                $newMessageListener
            );
        }

        $newParticipantListener = config('chat.new_participant_listener');

        if ($newParticipantListener && class_exists($newParticipantListener)) {
            $this->app['events']->listen(
                \Mmedia\LeChat\Events\ParticipantCreated::class,
                $newParticipantListener
            );
        }

        $participantDeletedListener = config('chat.participant_deleted_listener');

        if ($participantDeletedListener && class_exists($participantDeletedListener)) {
            $this->app['events']->listen(
                \Mmedia\LeChat\Events\ParticipantDeleted::class,
                $participantDeletedListener
            );
        }

        if (config('chat.update_sender_read_at_on_message_created', true)) {
            $this->app['events']->listen(
                \Mmedia\LeChat\Events\MessageCreated::class,
                \Mmedia\LeChat\Listeners\UpdatedChatParticipantReadAtOnMessageCreated::class
            );
        }
    }
}
