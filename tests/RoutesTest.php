<?php

use Illuminate\Support\Facades\Route;

it('registers the API routes from the default package config', function () {
    expect(Route::has('chatrooms.index'))->toBeTrue()
        ->and(Route::has('chatrooms.markAsRead'))->toBeTrue()
        ->and(Route::has('chatrooms.messages.show'))->toBeTrue();
});
