<?php

use Mmedia\LeChat\Models\Chatroom;

it('can create a chatroom with a title', function () {
    $chatroom = Chatroom::create(['title' => 'General Chat']);

    expect($chatroom->title)->toBe('General Chat');
});
