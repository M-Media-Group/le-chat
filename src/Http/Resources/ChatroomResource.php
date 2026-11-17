<?php

namespace Mmedia\LeChat\Http\Resources;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\Chatroom;

/** @mixin Chatroom */
class ChatroomResource extends JsonResource
{
    /**
     * @return AnonymousResourceCollection<ChatroomResource>
     */
    public static function collection(mixed $resource): AnonymousResourceCollection
    {
        return parent::collection($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'messages' => $this->whenLoaded(
                'messages',
                fn (EloquentCollection $messages): AnonymousResourceCollection => MessageResource::collection($messages),
            ),
            'latest_message' => $this->whenLoaded(
                'latestMessage',
                fn (?ChatMessage $message): ?MessageResource => $message === null ? null : new MessageResource($message),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'description' => $this->description,
            'participants' => $this->whenLoaded(
                'participants',
                fn (EloquentCollection $participants): AnonymousResourceCollection => ChatParticipantResource::collection($participants),
            ),
            'unread_messages_count' => $this->whenCounted('unread_messages_count'),
        ];
    }
}
