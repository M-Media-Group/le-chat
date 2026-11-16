<?php

namespace Mmedia\LeChat;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Ai\Contracts\ConversationStore;
use Mmedia\LeChat\Ai\ChatroomConversationStore;
use Mmedia\LeChat\Ai\DelegatedRuns;
use Mmedia\LeChat\Ai\RequesterApprovalPolicy;
use Mmedia\LeChat\Commands\DecryptMessages;
use Mmedia\LeChat\Commands\DeleteMessages;
use Mmedia\LeChat\Commands\EncryptMessages;
use Mmedia\LeChat\Commands\ExpireApprovals;
use Mmedia\LeChat\Commands\LeChatCommand;
use Mmedia\LeChat\Commands\NotifyUsersOfRecentUnreadMessages;
use Mmedia\LeChat\Commands\PruneFiles;
use Mmedia\LeChat\Contracts\ApprovalPolicy;
use Mmedia\LeChat\Events\MessageCreated;
use Mmedia\LeChat\Events\ParticipantCreated;
use Mmedia\LeChat\Events\ParticipantDeleted;
use Mmedia\LeChat\Listeners\PromptAgentsOnMessageCreated;
use Mmedia\LeChat\Listeners\UpdatedChatParticipantReadAtOnMessageCreated;
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
            ->hasConfigFile('chat')
            ->hasViews()
            ->discoversMigrations()
            ->hasRoutes('channels')
            ->hasCommand(LeChatCommand::class)
            ->hasCommand(EncryptMessages::class)
            ->hasCommand(ExpireApprovals::class)
            ->hasCommand(DecryptMessages::class)
            ->hasCommand(DeleteMessages::class)
            ->hasCommand(NotifyUsersOfRecentUnreadMessages::class);
    }

    // Package config is merged only after configurePackage(), so features can't be read there
    public function packageRegistered(): void
    {
        $this->app->bindIf(ApprovalPolicy::class, RequesterApprovalPolicy::class);
        $this->app->scoped(DelegatedRuns::class);

        if (Features::enabled(Features::routes())) {
            $this->package->hasRoute('api');
        }
    }

    // The boot
    public function bootingPackage(): void
    {
        if (Features::enabled(Features::files())) {
            $this->package->hasCommand(PruneFiles::class);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (Features::enabled(Features::aiSdkIntegration()) && interface_exists(ConversationStore::class)) {
                $schedule->command(ExpireApprovals::class)->everyMinute()->withoutOverlapping(60);
            }
            if (Features::enabled(Features::files())) {
                $schedule->command(PruneFiles::class)->everyTenMinutes()->withoutOverlapping(60);
            }
            if (Features::enabled(Features::dailyUnreadNotifications())) {
                $schedule->command(NotifyUsersOfRecentUnreadMessages::class, [
                    '--days' => config('chat-options.daily-unread-notifications.days', 1),
                ])->dailyAt(config('chat-options.daily-unread-notifications.at', '09:00'))->withoutOverlapping(60);
            }
        });

        // Bound while booting so it wins over the AI SDK's own registration regardless of provider order
        if (Features::enabled(Features::aiSdkIntegration()) && interface_exists(ConversationStore::class)) {
            $this->app->singleton(ConversationStore::class, fn () => new ChatroomConversationStore);

            if (Features::optionEnabled(Features::aiSdkIntegration(), 'auto_respond')) {
                $this->app['events']->listen(MessageCreated::class, PromptAgentsOnMessageCreated::class);
            }
        }

        $newMessageListener = config('chat.new_message_listener');

        if ($newMessageListener && class_exists($newMessageListener)) {
            $this->app['events']->listen(
                MessageCreated::class,
                $newMessageListener
            );
        }

        $newParticipantListener = config('chat.new_participant_listener');

        if ($newParticipantListener && class_exists($newParticipantListener)) {
            $this->app['events']->listen(
                ParticipantCreated::class,
                $newParticipantListener
            );
        }

        $participantDeletedListener = config('chat.participant_deleted_listener');

        if ($participantDeletedListener && class_exists($participantDeletedListener)) {
            $this->app['events']->listen(
                ParticipantDeleted::class,
                $participantDeletedListener
            );
        }

        if (config('chat.update_sender_read_at_on_message_created', true)) {
            $this->app['events']->listen(
                MessageCreated::class,
                UpdatedChatParticipantReadAtOnMessageCreated::class
            );
        }
    }
}
