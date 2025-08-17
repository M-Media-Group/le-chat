<?php

namespace Mmedia\LeChat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
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
            'message' => $this->message,
            'chatroom_id' => $this->chatroom_id,
            'sender_id' => $this->sender_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'sender' => new ChatParticipantResource($this->whenLoaded('sender')),
            'replies' => MessageResource::collection($this->whenLoaded('replies')),
            'parent_message' => new MessageResource($this->whenLoaded('parentMessage')),
        ];
    }
}
