<?php

namespace Mmedia\LeChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $requester_message_id
 * @property int $assistant_message_id
 * @property int $generation
 * @property array<string, string> $fingerprints
 * @property string $status
 * @property Carbon $expires_at
 * @property string|null $approver_type
 * @property int|string|null $approver_id
 * @property string|null $idempotency_key
 * @property string|null $decision_hash
 * @property string|null $failure_reason
 * @property-read ChatMessage $requesterMessage
 * @property-read ChatMessage $assistantMessage
 */
/** @internal Persistent state for the AI SDK approval workflow. */
final class ChatApprovalBatch extends Model
{
    protected $fillable = ['requester_message_id', 'assistant_message_id', 'generation', 'fingerprints', 'status', 'expires_at', 'approver_type', 'approver_id', 'idempotency_key', 'decision_hash', 'failure_reason'];

    protected $hidden = ['fingerprints'];

    protected $casts = ['fingerprints' => 'array', 'expires_at' => 'datetime'];

    /** @return BelongsTo<ChatMessage, $this> */
    public function requesterMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'requester_message_id');
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'assistant_message_id');
    }
}
