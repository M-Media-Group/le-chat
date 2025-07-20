<?php

namespace Mmedia\LeChat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatParticipantResource extends JsonResource
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
            'participant_id' => $this->participant_id,
            'participant_type' => $this->participant_type,
            'display_name' => $this->display_name,
            'avatar_url' => $this->avatar_url,
            'created_at' => $this->created_at,
            'deleted_at' => $this->deleted_at,
            'chatroom_id' => $this->chatroom_id,
        ];
    }
}
