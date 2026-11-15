<?php

namespace Mmedia\LeChat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Features;
use RuntimeException;

/**
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property string $status
 * @property Carbon|null $expires_at
 */
final class ChatFile extends Model
{
    use Prunable;

    protected $fillable = ['owner_type', 'owner_id', 'disk', 'path', 'name', 'mime_type', 'size', 'sha256', 'status', 'expires_at'];

    protected $hidden = ['disk', 'path'];

    protected $casts = ['expires_at' => 'datetime', 'size' => 'integer'];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(
        Builder $query,
        ChatParticipantInterface $participant,
        bool $includeLeftChatrooms = true,
        ?bool $includeBeforeJoined = null
    ): Builder {
        return $query->where($this->qualifyColumn('status'), 'ready')
            ->whereHas('messages', fn (Builder $messages) => $messages
                ->visibleTo($participant, $includeLeftChatrooms, $includeBeforeJoined)
                ->withoutTrashed());
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $query = self::query();

        if (! Features::enabled(Features::files())) {
            return $query->whereKey([]);
        }

        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereIn('status', ['pending', 'ready', 'deleting'])
            ->whereDoesntHave('messages');
    }

    public function prune(): ?bool
    {
        $file = $this->getConnection()->transaction(function (): ?self {
            $file = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($file === null || ! $file->isExpiredAndUnshared()) {
                return null;
            }

            $file->update(['status' => 'deleting']);

            return $file;
        });

        if ($file === null) {
            return false;
        }

        if (! Storage::disk($file->disk)->delete($file->path)) {
            throw new RuntimeException("Unable to delete file {$file->getKey()}.");
        }

        return $file->delete();
    }

    private function isExpiredAndUnshared(): bool
    {
        return in_array($this->status, ['pending', 'ready', 'deleting'], true)
            && $this->expires_at !== null
            && $this->expires_at->isPast()
            && ! $this->messages()->exists();
    }

    /** @return BelongsToMany<ChatMessage, $this> */
    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(ChatMessage::class, 'chat_message_files');
    }
}
