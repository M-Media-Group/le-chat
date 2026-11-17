<?php

namespace Mmedia\LeChat\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use LogicException;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;

/**
 * @internal Membership timestamps bound history even after soft deletion.
 *
 * @template TDeclaringModel of ChatParticipant
 *
 * @extends HasManyThrough<ChatMessage, ChatParticipant, TDeclaringModel>
 */
final class MembershipMessages extends HasManyThrough
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes = []): never
    {
        throw new LogicException('This message relationship is read-only. Use the message-sending APIs.');
    }

    /** @param array<string, mixed> $options */
    public function save(Model $model, array $options = []): never
    {
        throw new LogicException('This message relationship is read-only. Use the message-sending APIs.');
    }

    /** @param iterable<Model> $models */
    public function saveMany(iterable $models): never
    {
        throw new LogicException('This message relationship is read-only. Use the message-sending APIs.');
    }

    public function throughParentSoftDeletes(): bool
    {
        return false;
    }
}
