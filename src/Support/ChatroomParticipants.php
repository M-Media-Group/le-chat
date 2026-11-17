<?php

namespace Mmedia\LeChat\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

/**
 * @internal
 *
 * @template TDeclaringModel of Chatroom
 *
 * @extends HasMany<ChatParticipant, TDeclaringModel>
 */
final class ChatroomParticipants extends HasMany
{
    protected function setForeignAttributesForCreate(Model $model): void
    {
        parent::setForeignAttributesForCreate($model);

        if (! $model->exists) {
            $model->setAttribute('can_see_messages_before_joined', $model->getAttribute('can_see_messages_before_joined')
                ?? $this->parent->getAttribute('can_see_messages_before_joined'));
        }
    }
}
