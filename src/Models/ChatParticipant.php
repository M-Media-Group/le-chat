<?php

namespace Mmedia\LaravelChat\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Traits\IsChatParticipant;

class ChatParticipant extends \Illuminate\Database\Eloquent\Model implements ChatParticipantInterface
{
    use IsChatParticipant, SoftDeletes, HasUlids;

    protected $fillable = [
        'chatroom_id',
        'participant_id',
        'participant_type',
        'role',

        // The participant may be non-related (e,g. a bot or external user), so we need to store a display name and a reference ID (could be a remote ID or a unique key)

        'display_name',
        'reference_id',
    ];

    /**
     * The chatroom this participant is in
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Chatroom, $this>
     */
    public function chat(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Chatroom::class, 'chatroom_id');
    }

    /**
     * The messages sent by this participant
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ChatMessage, $this>
     */
    public function sentMessages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    /**
     * All the messages in the chatroom this participant is in
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ChatMessage, $this>
     */
    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        $chatMessageInstance = new ChatMessage();
        return $this->hasMany(ChatMessage::class, 'chatroom_id', 'chatroom_id')
            // where created_at is after the time this participant was created at
            ->whereColumn(
                $chatMessageInstance->getQualifiedCreatedAtColumn(),
                '>=',
                'created_at'
            );
    }

    /**
     * The participant model (the user or other model this participant represents)
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function participant(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
