<?php

namespace Mmedia\LeChat\Http\Controllers;

use Aws\S3\PostObjectV4;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\PathPrefixer;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Models\ChatFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatFileController extends Controller
{
    /**
     * @response JsonResponse<array{id: int, status: 'pending', upload_method: 'POST', upload_url: string, upload_fields: array<string, string>|array{}}, 201>
     */
    public function store(Request $request): JsonResponse
    {
        $this->abortUnlessFilesEnabled();
        $user = $this->participantUser($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'mime_type' => 'required|string|max:255',
            'size' => 'required|integer|min:1|max:'.(config('chat-options.files.max_size', 10240) * 1024),
            'sha256' => 'required|string|regex:/\A[a-f0-9]{64}\z/',
        ]);
        $allowed = config('chat-options.files.allowed_mime_types', []);
        if ($allowed !== [] && ! in_array($validated['mime_type'], $allowed, true)) {
            throw ValidationException::withMessages(['mime_type' => 'The file type is not allowed.']);
        }
        $disk = config('chat-options.files.disk') ?? Storage::getDefaultDriver();
        if ((Storage::disk($disk)->getConfig()['visibility'] ?? null) === 'public') {
            throw new \RuntimeException('The file disk must be private.');
        }
        if (! $user instanceof Model || $user->getConnection()->getName() !== (new ChatFile)->getConnection()->getName()) {
            throw new \LogicException('The file owner must be a model on the chat database connection.');
        }
        $file = $user->getConnection()->transaction(function () use ($user, $validated, $disk): ChatFile {
            $user->newQueryWithoutScopes()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $owned = ChatFile::query()->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey())
                ->whereNotNull('expires_at')->where('expires_at', '>', now());
            if ((clone $owned)->where('status', 'pending')->count() >= config('chat-options.files.max_pending_uploads', 10) ||
                (clone $owned)->where('status', 'ready')->count() >= config('chat-options.files.max_unshared_files', 100)) {
                throw ValidationException::withMessages(['file' => 'The owner has reached the unshared file quota.']);
            }

            return ChatFile::create([
                ...$validated,
                'owner_type' => $user->getMorphClass(), 'owner_id' => $user->getKey(), 'disk' => $disk,
                'path' => trim(config('chat-options.files.path', 'chat-files'), '/').'/pending/'.Str::uuid(),
                'status' => 'pending', 'expires_at' => now()->addHours(config('chat-options.files.pending_ttl_hours', 1)),
            ]);
        });

        return response()->json([
            'id' => $file->id,
            'status' => 'pending',
            ...$this->fileUploadInstructions($file),
        ], 201);
    }

    /** @return array{upload_method: string, upload_url: string, upload_fields: array<string, string>} */
    private function fileUploadInstructions(ChatFile $file): array
    {
        $disk = Storage::disk($file->disk);
        if (! $disk instanceof AwsS3V3Adapter || ! class_exists(PostObjectV4::class)) {
            return ['upload_method' => 'POST', 'upload_url' => route('chat.files.content', ['file' => $file->id]), 'upload_fields' => []];
        }

        $configuration = $disk->getConfig();
        $prefixer = new PathPrefixer($configuration['root'] ?? '', '/');
        if (isset($configuration['prefix'])) {
            $prefixer = new PathPrefixer($prefixer->prefixPath($configuration['prefix']), '/');
        }
        $key = $prefixer->prefixPath($file->path);
        $checksum = base64_encode(hex2bin($file->sha256));
        $fields = [
            'key' => $key,
            'Content-Type' => $file->mime_type,
            'x-amz-checksum-algorithm' => 'SHA256',
            'x-amz-checksum-sha256' => $checksum,
        ];
        $policy = new PostObjectV4($disk->getClient(), $configuration['bucket'], $fields, [
            ['bucket' => $configuration['bucket']],
            ['key' => $key],
            ['Content-Type' => $file->mime_type],
            ['x-amz-checksum-algorithm' => 'SHA256'],
            ['x-amz-checksum-sha256' => $checksum],
            ['content-length-range', $file->size, $file->size],
        ], $file->expires_at);

        return [
            'upload_method' => 'POST',
            'upload_url' => $policy->getFormAttributes()['action'],
            'upload_fields' => $policy->getFormInputs(),
        ];
    }

    /** @throws ModelNotFoundException */
    public function uploadContent(Request $request, int $file): JsonResponse
    {
        $this->abortUnlessFilesEnabled();
        $user = $this->participantUser($request);
        $validated = $request->validate(['file' => ['required', 'file', 'max:'.config('chat-options.files.max_size', 10240)]]);
        $stored = ChatFile::query()->whereKey($file)->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey())->firstOrFail();
        $stored->getConnection()->transaction(function () use ($stored, $validated): void {
            $stored->refresh();
            $stored = ChatFile::query()->whereKey($stored->getKey())->lockForUpdate()->firstOrFail();
            if ($stored->status !== 'pending' || $stored->expires_at === null || $stored->expires_at->isPast()) {
                throw ValidationException::withMessages(['file' => 'The upload is no longer pending.']);
            }
            $path = $validated['file']->storeAs(dirname($stored->path), basename($stored->path), ['disk' => $stored->disk, 'visibility' => 'private']);
            if ($path === false) {
                throw new \RuntimeException('Unable to store file.');
            }
        });

        return response()->json(['status' => 'pending']);
    }

    /**
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function update(Request $request, int $file): JsonResponse
    {
        $this->abortUnlessFilesEnabled();
        $user = $this->participantUser($request);
        $stored = ChatFile::query()->whereKey($file)->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey())->firstOrFail();
        $temporaryPath = $stored->path;
        $readyPath = trim(config('chat-options.files.path', 'chat-files'), '/').'/ready/'.Str::uuid();
        $disk = Storage::disk($stored->disk);
        try {
            $stored->getConnection()->transaction(function () use ($stored, $disk, $temporaryPath, $readyPath, $user): void {
                if (! $user instanceof Model || $user->getConnection()->getName() !== $stored->getConnection()->getName()) {
                    throw new \LogicException('The file owner must be a model on the chat database connection.');
                }
                $user->newQueryWithoutScopes()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $stored = ChatFile::query()->whereKey($stored->getKey())->lockForUpdate()->firstOrFail();
                if ($stored->status !== 'pending' || $stored->expires_at === null || $stored->expires_at->isPast() || ! $disk->exists($temporaryPath)) {
                    throw ValidationException::withMessages(['file' => 'The pending upload is missing or expired.']);
                }
                $unsharedCount = ChatFile::query()->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey())
                    ->where('status', 'ready')->whereNotNull('expires_at')->where('expires_at', '>', now())->count();
                if ($unsharedCount >= config('chat-options.files.max_unshared_files', 100)) {
                    throw ValidationException::withMessages(['file' => 'The owner has reached the unshared file quota.']);
                }
                if ($disk->size($temporaryPath) !== $stored->size) {
                    throw ValidationException::withMessages(['file' => 'The uploaded file size does not match the declaration.']);
                }
                if (! $disk->copy($temporaryPath, $readyPath)) {
                    throw new \RuntimeException('Unable to finalize file.');
                }
                $stream = $disk->readStream($readyPath);
                if (! is_resource($stream)) {
                    throw new \RuntimeException('Unable to verify stored file.');
                }
                $mimeFile = tmpfile();
                if (! is_resource($mimeFile)) {
                    fclose($stream);
                    throw new \RuntimeException('Unable to prepare MIME verification.');
                }
                try {
                    $hash = hash_init('sha256');
                    $size = 0;
                    while (! feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if ($chunk === false) {
                            throw new \RuntimeException('Unable to read stored file.');
                        }
                        $size += strlen($chunk);
                        if (fwrite($mimeFile, $chunk) !== strlen($chunk)) {
                            throw new \RuntimeException('Unable to prepare MIME verification.');
                        }
                        hash_update($hash, $chunk);
                    }
                    $checksum = hash_final($hash);
                    $mimePath = stream_get_meta_data($mimeFile)['uri'] ?? null;
                    $mime = is_string($mimePath)
                        ? ((new \finfo(FILEINFO_MIME_TYPE))->file($mimePath) ?: 'application/octet-stream')
                        : 'application/octet-stream';
                } finally {
                    fclose($stream);
                    fclose($mimeFile);
                }
                if ($size !== $stored->size || ! hash_equals($stored->sha256, $checksum) || $mime !== $stored->mime_type) {
                    throw ValidationException::withMessages(['file' => 'The uploaded file does not match the declared metadata.']);
                }
                $stored->update(['path' => $readyPath, 'status' => 'ready', 'expires_at' => now()->addHours(config('chat-options.files.unshared_ttl_hours', 24))]);
            });
        } catch (\Throwable $exception) {
            $disk->delete($readyPath);
            throw $exception;
        }
        $disk->delete($temporaryPath);

        return response()->json(['id' => $stored->id, 'status' => 'ready']);
    }

    /** @throws ModelNotFoundException */
    public function show(Request $request, int $file): StreamedResponse
    {
        $this->abortUnlessFilesEnabled();
        $user = $this->participantUser($request);
        $stored = ChatFile::query()->visibleTo($user)->findOrFail($file);

        return Storage::disk($stored->disk)->download($stored->path, $stored->name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function participantUser(Request $request): ChatParticipantInterface
    {
        $user = $request->user();

        if (! ($user instanceof ChatParticipantInterface)) {
            throw new HttpResponseException(
                response()->json(['error' => 'Authenticated user is not a chat participant'], 400)
            );
        }

        return $user;
    }

    private function abortUnlessFilesEnabled(): void
    {
        abort_unless(Features::enabled(Features::files()), 404);
    }
}
