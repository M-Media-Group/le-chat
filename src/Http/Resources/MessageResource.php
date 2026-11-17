<?php

namespace Mmedia\LeChat\Http\Resources;

use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Models\ChatFile;
use Mmedia\LeChat\Models\ChatMessage;

/** @mixin ChatMessage */
class MessageResource extends JsonResource
{
    /**
     * @return AnonymousResourceCollection<MessageResource>
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
            'message' => $this->messageFor($request),
            'blocks' => $this->whenHas('blocks', fn (?array $blocks): ?array => $this->blocksWithApprovalState($blocks)),
            'version' => $this->whenHas('version'),
            'edited_at' => $this->whenHas('edited_at'),
            'approval' => $this->whenLoaded('approvalBatch', fn () => $this->approvalBatch === null ? null : [
                'id' => $this->approvalBatch->id,
                'status' => $this->approvalBatch->status,
                'expires_at' => $this->approvalBatch->expires_at,
            ]),
            'chatroom_id' => $this->chatroom_id,
            'sender_id' => $this->sender_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'sender' => new ChatParticipantResource($this->whenLoaded('sender')),
            'replies' => MessageResource::collection($this->whenLoaded('replies')),
            'parent_message' => new MessageResource($this->whenLoaded('parentMessage')),
            'files' => $this->when(Features::enabled(Features::files()) && $this->resource->relationLoaded('files'), fn () => $this->files->map(fn (ChatFile $file): array => [
                'id' => $file->id, 'name' => $file->name, 'mime_type' => $file->mime_type, 'size' => $file->size,
                'url' => Route::has('chat.files.show') ? route('chat.files.show', ['file' => $file->id]) : null,
            ])),
            'metadata' => $this->whenHas(
                'metadata',
                function (?ArrayObject $metadata): ?object {
                    if ($metadata === null) {
                        return null;
                    }
                    $public = $metadata->getArrayCopy();
                    unset($public['ai'], $public['approval_required']);

                    return (object) $public;
                },
            ),
        ];
    }

    private function messageFor(Request $request): ?string
    {
        $message = $this->resource->getAttribute('message');
        if (! is_string($message)) {
            return null;
        }
        $actor = $request->user();
        $batch = $this->resource->relationLoaded('approvalBatch') ? $this->approvalBatch : null;
        if (! $actor instanceof ChatParticipantInterface || $batch === null || $batch->status !== 'resumed' || $batch->failure_reason === null
            || $batch->approver_type !== $actor->getMorphClass() || (string) $batch->approver_id !== (string) $actor->getKey()) {
            return $message;
        }

        return $message."\nReason: ".$batch->failure_reason;
    }

    /**
     * @param  list<array{schema_version: 1, type: 'section', block_id: string, text: string}|array{schema_version: 1, type: 'actions', block_id: string, elements: list<array{type: 'button', action_id: string, text: string}>}>|null  $blocks
     * @return list<array{schema_version: 1, type: 'section', block_id: string, text: string}|array{schema_version: 1, type: 'actions', block_id: string, elements: list<array{type: 'button', action_id: string, text: string}>, state?: string}>|null
     */
    private function blocksWithApprovalState(?array $blocks): ?array
    {
        if ($blocks === null || ! $this->resource->relationLoaded('approvalBatch') || $this->approvalBatch === null) {
            return $blocks;
        }

        $actionBlockId = 'approval-'.$this->approvalBatch->id.'-actions';

        return array_map(fn (array $block): array => $block['block_id'] === $actionBlockId
            ? [...$block, 'state' => $this->approvalBatch->status]
            : $block, $blocks);
    }
}
