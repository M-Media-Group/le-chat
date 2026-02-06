<?php

namespace Mmedia\LeChat\Traits;

use Illuminate\Database\Eloquent\Builder;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\Chatroom;

/**
 * @template M of \Illuminate\Database\Eloquent\Model
 *
 * @mixin M
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait BelongsToChatroom
{
    /**
     * The chatroom this participant is in
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Chatroom, $this>
     */
    public function chatroom(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Chatroom::class, 'chatroom_id');
    }

    /**
     * Scope to filter messages sent in a specific chatroom.
     *
     * @param  Builder<ChatMessage>  $query
     * @return Builder<ChatMessage>
     */
    public function scopeInRoom(Builder $query, Chatroom $chatroom): Builder
    {
        return $query->where(
            $this->qualifyColumn('chatroom_id'),
            $chatroom->getKey()
        );
    }
}
