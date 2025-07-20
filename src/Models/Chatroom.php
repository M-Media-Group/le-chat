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

    /**
     * Get the latest message in the chatroom.
     *
     * @note we DO NOT use latestOfMany here because it does not support soft deletes. It will cause latestMessage to fail if the user has been removed from the chatroom - this is because it tries to access the latest message that might not be visible to the user, and the subsequent visibleTo filters out the only message.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<ChatMessage, $this>
     */
    public function latestMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ChatMessage::class)->orderByDesc(ChatMessage::CREATED_AT);
    }

    /**
     * Each chatroom has one or more ChatParticipants. The ChatParticipant contains a morph to the user or other model
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ChatParticipant, $this>
     */
    public function participants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    /**
     * Each chatroom has one or more ChatParticipants. The ChatParticipant contains a morph to the user or other model
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<ChatParticipant, $this>
     */
    public function participantsWithTrashed(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChatParticipant::class)->withTrashed();
    }

    /**
     * Send a message to the chatroom as a participant.
     */
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

    /**
     * Send a message as the system, unattached to any participant.
     */
    public function sendMessage(string $message, array $options = []): ChatMessage
    {
        $chatMessage = new ChatMessage([
            'sender_id' => null, // No sender, system message
            'chatroom_id' => $this->getKey(),
        ])
            ->forceFill($options)
            ->setAttribute('message', $message);

        $chatMessage->save();

        return $chatMessage;
    }

    public function markAsReadBy(ChatParticipantInterface|ChatParticipant $participant, ChatMessage|\DateTime|\Carbon\Carbon|null $message = null): bool
    {
        // Get the chat participant for this model in the given chat room
        $chatParticipant = $this->participant($participant, true);
        if (! $chatParticipant) {
            return false; // Not a participant in this chatroom
        }

        // Mark as read at the time of the message or now
        if ($message instanceof ChatMessage) {
            $chatParticipant->read_at = $message->created_at;
        } elseif ($message instanceof \DateTime || $message instanceof \Carbon\Carbon) {
            $chatParticipant->read_at = $message;
        } else {
            $chatParticipant->read_at = now(); // Mark as read at the current time
        }

        return $chatParticipant->save();
    }

    public function addParticipant(ChatParticipantInterface $participant, string $role = 'member'): ChatParticipant
    {
        $chatParticipant = new ChatParticipant([
            'chatroom_id' => $this->getKey(),
            'participant_id' => $participant->getKey(),
            'participant_type' => $participant->getMorphClass(),
            'role' => $role,
        ]);

        $chatParticipant->save();

        return $chatParticipant;
    }

    /**
     * Add multiple participants to the chatroom.
     *
     * @param  ChatParticipantInterface[]  $participants
     * @return ChatParticipant[]
     */
    public function addParticipants(array $participants, string $role = 'member'): array
    {
        $chatParticipants = [];
        foreach ($participants as $participant) {
            $chatParticipants[] = $this->addParticipant($participant, $role);
        }

        return $chatParticipants;
    }

    /**
     * Remove a participant from the chatroom.
     */
    public function removeParticipant(ChatParticipantInterface|ChatParticipant $participant): bool
    {
        $chatParticipant = $this->participant($participant);
        if ($chatParticipant) {
            return $chatParticipant->delete();
        }

        return false;
    }

    public function removeParticipants(array $participants): bool
    {
        $removed = false;
        foreach ($participants as $participant) {
            $removed |= $this->removeParticipant($participant);
        }

        return $removed;
    }

    /**
     * Sync participants in the chatroom.
     * This will add new participants, remove participants that are no longer present,
     * and leave existing ones untouched.
     *
     * @param  ChatParticipantInterface[]  $participants
     * @return array{added: int[], removed: int[]} An array with the IDs of added and removed ChatParticipant models.
     */
    public function syncParticipants(array $participants, string $role = 'member'): array
    {
        // A helper to create a unique key (e.g., 'App\Models\User:1') for any participant object.
        $makeKey = fn (ChatParticipantInterface $p) => $p->getMorphClass().':'.$p->getKey();

        // 1. Create a map of the NEW participants we want in the room, keyed by their unique identifier.
        //    Example: ['App\Models\User:2' => $user2_object, 'App\Models\User:3' => $user3_object]
        $newParticipantMap = collect($participants)->keyBy($makeKey);

        // 2. Create a map of the CURRENT participants in the database, also keyed by their unique identifier.
        //    We use participant_type and participant_id to build the same key format.
        //    Example: ['App\Models\User:1' => $chatParticipant1, 'App\Models\User:2' => $chatParticipant2]
        $currentParticipantMap = $this->participants()->get()->keyBy(
            fn (ChatParticipant $p) => $p->participant_type.':'.$p->participant_id
        );

        // 3. Find which participants to ADD.
        //    These are keys present in the new map but not in the current map.
        $keysToAdd = $newParticipantMap->keys()->diff($currentParticipantMap->keys());
        $participantsToAdd = $newParticipantMap->whereIn(null, $keysToAdd);

        // 4. Find which participants to REMOVE.
        //    These are keys present in the current map but not in the new map.
        $keysToRemove = $currentParticipantMap->keys()->diff($newParticipantMap->keys());
        $participantsToRemove = $currentParticipantMap->whereIn(null, $keysToRemove);

        $results = [
            'added' => [],
            'removed' => [],
        ];

        // 5. Perform the additions.
        foreach ($participantsToAdd as $participant) {
            $newRecord = $this->addParticipant($participant, $role);
            $results['added'][] = $newRecord->getKey();
        }

        // 6. Perform the removals.
        //    We can use a more efficient single `whereIn` delete query.
        if ($participantsToRemove->isNotEmpty()) {
            $idsToRemove = $participantsToRemove->pluck('id')->all();
            $this->participants()->whereIn('id', $idsToRemove)->delete();
            $results['removed'] = $idsToRemove;
        }

        // Refresh the relation in case it's used again in the same request.
        $this->load('participants');

        return $results;
    }

    public function hasParticipant(ChatParticipantInterface|ChatParticipant $participant, bool $includeTrashed = false): bool
    {
        return $this->participants()->ofParticipant($participant)->when($includeTrashed, fn ($query) => $query->withTrashed())->exists();
    }

    public function hasOrHadParticipant(ChatParticipantInterface|ChatParticipant $participant): bool
    {
        return $this->hasParticipant($participant, true);
    }

    /**
     * Returns the ChatParticipant instance for the given participant in this chatroom.
     */
    public function participant(ChatParticipantInterface|ChatParticipant $participant, bool $includeTrashed = false): ?ChatParticipant
    {
        return $this->participants()->ofParticipant($participant)->when($includeTrashed, fn ($query) => $query->withTrashed())->first();
    }

    /**
     * Get chatrooms that have at least the given participants.
     *
     * @param  (ChatParticipantInterface|ChatParticipant)[]  $participant
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeHavingParticipants(
        \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query,
        array $participants,
        bool $includeTrashed = false
    ) {
        return $query->whereHas(
            'participants',
            function ($query) use ($participants, $includeTrashed) {
                foreach ($participants as $participant) {
                    return $query->ofParticipant($participant)->when($includeTrashed, fn ($query) => $query->withTrashed());
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
        array $participants,
        bool $includeTrashed = false
    ) {
        $total = count($participants);

        return $query->havingParticipants($participants, $includeTrashed)
            ->whereHas(
                'participants',
                null,
                '=',
                $total
            );
    }

    /**
     * Get participants that morph into models with the Notifiable trait.
     * This is useful for sending notifications to participants.
     *
     * @return \Illuminate\Database\Eloquent\Collection<ChatParticipant>
     */
    public function getNotifiableParticipants(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->participants->filter(
            fn (ChatParticipant $participant) => $participant->is_notifiable
        )->values();
    }

    public function scopeWithUnreadMessagesCountFor($query, ChatParticipantInterface|ChatParticipant $participant)
    {
        return $query->withCount(['messages as unread_messages_count' => function ($query) use ($participant) {
            $query->visibleTo($participant)->unreadBy($participant);
        }]);
    }
}
