<?php

use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

it('can create a chatroom with a name', function () {
    $chatroom = Chatroom::create(['name' => 'General Chat']);

    expect($chatroom->name)->toBe('General Chat');
});

it('syncs participants while retaining existing participants', function () {
    $chatroom = Chatroom::create(['name' => 'General Chat']);
    $otherRoom = Chatroom::create(['name' => 'Other Chat']);
    $existingParticipant = ChatParticipant::create([
        'chatroom_id' => $chatroom->getKey(),
        'reference_id' => 'existing',
    ]);
    $newParticipant = ChatParticipant::create([
        'chatroom_id' => $otherRoom->getKey(),
        'reference_id' => 'new',
    ]);
    $chatroom->addParticipant($existingParticipant);

    $result = $chatroom->syncParticipants([$existingParticipant, $newParticipant]);

    expect($chatroom->participants()->count())->toBe(2)
        ->and($result['added'])->toHaveCount(1)
        ->and($result['removed'])->toBe([]);
});
