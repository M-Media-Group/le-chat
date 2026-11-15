<?php

namespace Mmedia\LeChat\Ai;

use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\Video;
use Mmedia\LeChat\Models\ChatFile;
use RuntimeException;

/** @internal Converts shared chat files to Laravel AI SDK attachments. */
final class ChatAttachments
{
    /**
     * @param  iterable<ChatFile>  $files
     * @return list<File>
     */
    public static function fromFiles(iterable $files): array
    {
        $attachments = [];

        foreach ($files as $file) {
            if ($file->status !== 'ready' || ! Storage::disk($file->disk)->exists($file->path)) {
                throw new RuntimeException('A chat attachment is unavailable.');
            }

            $attachment = match (strtok($file->mime_type, '/')) {
                'image' => Image::fromStorage($file->path, $file->disk),
                'audio' => Audio::fromStorage($file->path, $file->disk),
                'video' => Video::fromStorage($file->path, $file->disk),
                default => Document::fromStorage($file->path, $file->disk),
            };

            $attachments[] = $attachment->as($file->name)->withMimeType($file->mime_type);
        }

        return $attachments;
    }
}
