<?php

namespace Mmedia\LaravelChat\Traits;

use Illuminate\Database\Eloquent\Builder;
use \Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Mmedia\LaravelChat\Contracts\ChatParticipantInterface;
use Mmedia\LaravelChat\Models\ChatMessage;
use Mmedia\LaravelChat\Models\ChatParticipant;
use Mmedia\LaravelChat\Models\Chatroom;

/**
 * @template T of \Mmedia\LaravelChat\Contracts\ChatParticipantInterface
 *
 * @mixin T
 *
 * @template M of \Illuminate\Database\Eloquent\Model
 *
 * @mixin M
 *
 * @phpstan-require-implements \Mmedia\LaravelChat\Contracts\ChatParticipantInterface
 *
 * @see \Mmedia\LaravelChat\Contracts\ChatParticipantInterface
 */
trait IsChatParticipant
{
    /**
     * The chat participants for this model (the inverse of the morphTo on ChatParticipant).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<ChatParticipant, M>
     */
    public function chatParticipants(): MorphMany
    {
        // 'participant' is the morph name defined in the ChatParticipant model's morphTo() method
        return $this->morphMany(ChatParticipant::class, 'participant');
    }

    /**
     * There is one chatParticipant per chat room for a given model using this trait.
     */
    private function asChatParticipantIn(Chatroom $chatRoom): ?ChatParticipant
    {
        // Get the chat participant for this model in the given chat room.
        // This will return a single ChatParticipant model instance.
        return $this->chatParticipants()
            ->where('chatroom_id', $chatRoom->getKey())
            ->first();
    }

    /**
     * Get the chat rooms this model is a participant in.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Chatroom>
     */
    public function chatRooms(): Builder
    {
        // We need to join ChatRooms through the ChatParticipants table.
        // Start from the Chatroom model query builder.
        return Chatroom::query()
            ->whereHas('participants', function (Builder $query) {
                // Filter the participants to include only those that are linked to this model
                $query->where('participant_id', $this->getKey())
                    ->where('participant_type', $this->getMorphClass());
            })->distinct();
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ChatMessage>
     */
    public function sentMessages(): Builder
    {
        // We need to get ChatMessages that are linked to a ChatParticipant
        // where that ChatParticipant is linked to this model.
        // Start from the ChatMessage model query builder.
        return ChatMessage::query()
            // Join the chat_participants table.
            // The sender_id on chat_messages links to the id on chat_participants.
            ->join('chat_participants', 'chat_messages.sender_id', '=', 'chat_participants.id')
            // Filter the joined chat_participants records
            ->where('chat_participants.participant_id', $this->getKey())
            ->where('chat_participants.participant_type', $this->getMorphClass())
            // Select only the columns from the chat_messages table
            ->select('chat_messages.*');
        // Distinct is likely not needed here.
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     */
    public function getSentMessages(): \Illuminate\Database\Eloquent\Collection
    {

        return $this->sentMessages()->get();
    }

    /**
     * Loads all messages for the given model via the participant relationship. Filtered to only messages created after column created_at in the pivot table.
     *
     * @return \Illuminate\Support\Collection<ChatMessage, $this>
     */
    public function getMessages(): Collection
    {

        return $this->chatParticipants()
            ->with('messages')
            ->get()
            ->flatMap(fn($participant) => $participant->messages);
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     */
    private function getMessagesSentToParticipant(ChatParticipantInterface $participant): Collection
    {
        return $this->sentMessages()
            // Filter the joined chat_participants records
            ->where('chat_participants.participant_id', $participant->getKey())
            ->where('chat_participants.participant_type', $participant->getMorphClass())
            ->get();
    }

    /**
     * Get the best channel for the given participants. Returns the latest used chatroom that contains all and only the given participants (plus this model).
     *
     * @param  ChatParticipantInterface[]  $participants
     */
    public function getBestChannelForParticipants(array $participants): ?Chatroom
    {
        // Add the current model to the list of participants to count
        $allParticipants = $participants;
        $allParticipants[] = $this; // Add this model (which implements ChatParticipantInterface)

        $expectedParticipantCount = count($allParticipants);

        // Get the chat rooms this model is a participant in.
        $chatRooms = $this->chatRooms();

        // Filter the chat rooms to include only those that contain ALL specified participants.
        foreach ($participants as $participant) {
            $chatRooms->whereHas('participants', function (Builder $query) use ($participant) {
                $query->where('participant_id', $participant->getKey())
                    ->where('participant_type', $participant->getMorphClass());
            });
        }

        // Now, filter these rooms to ensure they contain *exactly* the expected number of participants.
        // This requires joining the chat_participants table again, grouping, and counting.
        $chatRooms->select('chatrooms.*') // Ensure we are selecting chat room columns
            ->join('chat_participants as cp_count', 'chatrooms.id', '=', 'cp_count.chatroom_id')
            ->groupBy('chatrooms.id') // Group by chat room to count participants per room
            ->havingRaw('COUNT(DISTINCT cp_count.id) = ?', [$expectedParticipantCount]); // Count distinct participant IDs

        // Get the latest chat room from the filtered set
        return $chatRooms->latest('chatrooms.updated_at')->first(); // Order by chat room's updated_at for "latest used"
    }

    /**
     * Get all messages sent by this model across all their chat participants.
     */
    private function getMessagesSentToChatRoom(Chatroom $chatRoom): Collection
    {
        return $this->sentMessages()
            // Filter the joined chat_participants records
            ->where('chat_participants.chatroom_id', $chatRoom->getKey())
            ->get();
    }

    public function getMessagesSentTo(Chatroom|ChatParticipantInterface $target): Collection
    {
        if ($target instanceof Chatroom) {
            return $this->getMessagesSentToChatRoom($target);
        }

        return $this->getMessagesSentToParticipant($target);
    }

    private function sendMessageToChatRoom(Chatroom $chatRoom, string $message): ChatMessage
    {
        // Create a new message in the chat room
        return $chatRoom->messages()->create([
            'sender_id' => $this->asChatParticipantIn($chatRoom)->getKey(),
            'message' => $message,
        ]);
    }

    private function sendMessageToParticipant(ChatParticipantInterface $participant, string $message): ChatMessage
    {
        $bestChannel = $this->getBestChannelForParticipants([$participant]);
        if (! $bestChannel) {
            throw new \Exception('No chat room found for the given participants');
        }

        return $this->sendMessageToChatRoom($bestChannel, $message);
    }

    /**
     * Sends a message to a chat or a participant
     *
     * @param  Chatroom|ChatParticipantInterface|ChatParticipantInterface[]  $recipient
     */
    public function sendMessageTo(Chatroom|ChatParticipantInterface|array $recipient, string $message, bool $forceNewChannel = false, array $newChannelConfiguration = []): ChatMessage
    {
        if ($recipient instanceof Chatroom) {
            return $this->sendMessageToChatRoom($recipient, $message);
        }

        // Normalize the recipient to an array of ChatParticipantInterface
        if ($recipient instanceof ChatParticipantInterface) {
            $recipient = [$recipient];
        }

        if ($forceNewChannel || ! $bestChannel = $this->getBestChannelForParticipants($recipient)) {
            // Create a new chat room with the given configuration
            $bestChannel = Chatroom::create($newChannelConfiguration);
            // Add this model and the recipient to the chat room
            $this->chatParticipants()->create([
                'chatroom_id' => $bestChannel->getKey(),
                'participant_id' => $this->getKey(),
                'participant_type' => $this->getMorphClass(),
            ]);

            foreach ($recipient as $participant) {
                $bestChannel->participants()->create([
                    'chatroom_id' => $bestChannel->getKey(),
                    'participant_id' => $participant->getKey(),
                    'participant_type' => $participant->getMorphClass(),
                ]);
            }
        }

        // Send the message to the best channel
        return $this->sendMessageToChatRoom($bestChannel, $message);
    }
}
