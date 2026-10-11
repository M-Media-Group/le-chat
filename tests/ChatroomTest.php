<?php

use Mmedia\LeChat\Models\Chatroom;

it('can create a chatroom with a name', function () {
    $chatroom = Chatroom::create(['name' => 'General Chat']);

    expect($chatroom->name)->toBe('General Chat');
});
