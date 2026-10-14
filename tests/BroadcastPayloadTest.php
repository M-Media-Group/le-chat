<?php

use Mmedia\LeChat\Support\BroadcastPayload;

it('leaves a payload under the limit untouched', function () {
    $payload = ['id' => 1, 'message' => 'Hello', 'metadata' => ['a' => 'b']];

    expect(BroadcastPayload::fit($payload))->toBe($payload);
});

it('drops metadata first when over the limit', function () {
    $payload = [
        'id' => 1,
        'message' => 'Hello',
        'metadata' => ['blob' => str_repeat('x', BroadcastPayload::MAX_BYTES)],
    ];

    expect(BroadcastPayload::fit($payload))->toBe(['id' => 1, 'message' => 'Hello']);
});

it('drops the message too when metadata removal is not enough', function () {
    $payload = [
        'id' => 1,
        'message' => str_repeat('x', BroadcastPayload::MAX_BYTES),
        'metadata' => ['a' => 'b'],
    ];

    expect(BroadcastPayload::fit($payload))->toBe(['id' => 1]);
});

it('keeps the message when only metadata is oversized', function () {
    $payload = [
        'message' => 'Short',
        'metadata' => str_repeat('x', BroadcastPayload::MAX_BYTES),
    ];

    expect(BroadcastPayload::fit($payload))->toHaveKey('message')->not->toHaveKey('metadata');
});

it('tolerates payloads without metadata or message keys', function () {
    $payload = ['body' => str_repeat('x', BroadcastPayload::MAX_BYTES)];

    expect(BroadcastPayload::fit($payload))->toBe($payload);
});
