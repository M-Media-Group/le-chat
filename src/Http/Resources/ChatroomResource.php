<?php

namespace Mmedia\LaravelChat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatroomResource extends JsonResource
{
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
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'latest_message' => new MessageResource($this->whenLoaded('latestMessage')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'description' => $this->description,
            'participants' => ChatParticipantResource::collection($this->whenLoaded('participants')),
            'unread_messages_count' => $this->whenCounted('unread_messages_count'),
        ];
    }
}
