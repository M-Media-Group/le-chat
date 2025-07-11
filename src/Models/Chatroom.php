<?php

namespace Mmedia\LaravelChat\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;

class Chatroom extends \Illuminate\Database\Eloquent\Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'metadata',
    ];

    // Cast
    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * The messages exchanged in this chatroom
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ChatMessage, $this>
     */
    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    // Each chatroom has one or more ChatParticipants. The ChatParticipant contains a morph to the user or other model
    public function participants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function sendMessageAs(ChatParticipantInterface $participant, string $message): ChatMessage
    {
        $chatMessage = new ChatMessage([
            'sender_id' => $participant->getKey(),
            'chatroom_id' => $this->getKey(),
            'message' => $message,
        ]);

        $chatMessage->save();

        return $chatMessage;
    }

    public function addParticipant(ChatParticipantInterface $participant): ChatParticipant
    {
        $chatParticipant = new ChatParticipant([
            'chatroom_id' => $this->getKey(),
            'participant_id' => $participant->getKey(),
            'participant_type' => $participant->getMorphClass(),
        ]);

        $chatParticipant->save();

        return $chatParticipant;
    }

    public function hasParticipant(ChatParticipantInterface|ChatParticipant $participant): bool
    {
        return $this->participants()->ofParticipant($participant)->exists();
    }

    public function participant(ChatParticipantInterface|ChatParticipant $participant): ?ChatParticipant
    {
        return $this->participants()->ofParticipant($participant)->first();
    }

    /**
     * Get chatrooms that have at least the given participants.
     *
     * @param  array (ChatParticipantInterface|ChatParticipant)[]  $participant
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeHavingParticipants(
        \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query,
        array $participants
    ) {
        return $query->whereHas(
            'participants',
            function ($query) use ($participants) {
                foreach ($participants as $participant) {
                    return $query->ofParticipant($participant);
                }
            }
        );
    }

    /**
     * Get the chatrooms that have exactly the given participants.
     *
     * @param  array (ChatParticipantInterface|ChatParticipant)[]  $participant
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeHavingExactlyParticipants(
        \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query,
        array $participants
    ) {
        $total = count($participants);

        return $query->havingParticipants($participants)
            ->whereHas(
                'participants',
                null,
                '=',
                $total
            );
    }
}
