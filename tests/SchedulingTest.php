<?php

use Illuminate\Console\Scheduling\Schedule;
use Mmedia\LeChat\Features;

it('registers maintenance schedules for enabled features', function () {
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());
    $expiry = $events->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:expire-approvals'));
    $pruning = $events->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:prune-files'));

    expect($expiry)->toHaveCount(1)
        ->and($expiry->first()->expression)->toBe('* * * * *')
        ->and($expiry->first()->withoutOverlapping)->toBeTrue()
        ->and($pruning)->toHaveCount(1)
        ->and($pruning->first()->expression)->toBe('*/10 * * * *')
        ->and($pruning->first()->withoutOverlapping)->toBeTrue();
});

it('makes maintenance commands due at their intended intervals', function () {
    $schedule = app(Schedule::class);
    $this->travelTo(now()->setTime(12, 5, 0));

    $due = collect($schedule->dueEvents(app()));
    expect($due->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:expire-approvals')))->toHaveCount(1)
        ->and($due->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:prune-files')))->toHaveCount(0);

    $this->travelTo(now()->setTime(12, 10, 0));
    $due = collect($schedule->dueEvents(app()));
    expect($due->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:expire-approvals')))->toHaveCount(1)
        ->and($due->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:prune-files')))->toHaveCount(1);
});

it('schedules only enabled maintenance features', function (bool $aiEnabled, bool $filesEnabled) {
    config(['chat.features' => array_values(array_filter([
        $aiEnabled ? Features::aiSdkIntegration() : null,
        $filesEnabled ? Features::files() : null,
    ]))]);
    app()->forgetInstance(Schedule::class);

    $events = collect(app(Schedule::class)->events());

    expect($events->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:expire-approvals')))->toHaveCount($aiEnabled ? 1 : 0)
        ->and($events->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:prune-files')))->toHaveCount($filesEnabled ? 1 : 0);
})->with([
    'neither' => [false, false],
    'AI only' => [true, false],
    'files only' => [false, true],
    'both' => [true, true],
]);

it('schedules unread notifications only when explicitly enabled', function () {
    $events = collect(app(Schedule::class)->events());
    expect($events->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:notify-users-of-recent-unread-messages')))->toHaveCount(0);

    config(['chat.features' => [Features::dailyUnreadNotifications(['at' => '10:30', 'days' => 3])]]);
    app()->forgetInstance(Schedule::class);
    $events = collect(app(Schedule::class)->events());
    $notifications = $events->filter(fn ($event) => str_contains($event->command ?? '', 'le-chat:notify-users-of-recent-unread-messages'));

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->expression)->toBe('30 10 * * *')
        ->and($notifications->first()->command)->toContain('--days=3')
        ->and($notifications->first()->withoutOverlapping)->toBeTrue();
});
