<?php

namespace Mmedia\LeChat\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mmedia\LeChat\Ai\ApprovalBatches;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Events\BlockInteracted;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Http\Resources\ChatroomResource;
use Mmedia\LeChat\Http\Resources\MessageResource;
use Mmedia\LeChat\Models\ChatApprovalBatch;
use Mmedia\LeChat\Models\ChatMessage;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;
use Mmedia\LeChat\Support\MessageBlocks;

class ChatroomController extends Controller
{
    /**
     * Display a listing of the chatrooms.
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $user = $this->participantUser($request);

        $chatrooms = Chatroom::havingParticipants([$user], true)
            ->with('participants')
            ->withLatestMessageFor($user)
            ->withUnreadMessagesCountFor($user)
            ->get();

        return ChatroomResource::collection($chatrooms);
    }

    /**
     * Show the form for creating a new chatroom.
     */
    public function show(Request $request, Chatroom $chatroom): ChatroomResource|JsonResponse
    {
        $user = $this->participantUser($request);

        $this->abortUnlessHasOrHadParticipant($chatroom, $user);

        $chatroom->load(['participants', 'messages' => function ($query) use ($user) {
            $query->visibleTo($user)->with($this->messageRelations());
        }])
            ->loadCount(['messages as unread_messages_count' => function ($query) use ($user) {
                $query->visibleTo($user)->unreadBy($user)->withoutTrashed();
            }]);

        return new ChatroomResource($chatroom);
    }

    /**
     * Store a newly created chatroom in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->participantUser($request);

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $chatroom = Chatroom::create($request->only('name', 'description'));

        $chatroom->addParticipant($user, 'admin');

        return response()->json(new ChatroomResource($chatroom), 201);
    }

    /**
     * Update the specified chatroom in storage.
     */
    public function update(Request $request, Chatroom $chatroom): JsonResponse
    {
        $user = $this->participantUser($request);

        $chatParticipant = $chatroom->participant($user);

        if (! $chatParticipant || $chatParticipant->role !== 'admin') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $chatroom->update($request->only('name', 'description'));

        return response()->json(new ChatroomResource($chatroom), 200);
    }

    /**
     * Store a message in the chatroom.
     */
    public function storeMessage(Request $request): JsonResponse
    {
        $user = $this->participantUser($request);

        $validated = $request->validate([
            'to_entity_type' => 'required|string|in:chatroom,chat_participant',
            'to_entity_id' => 'required|integer',
            'message' => Features::enabled(Features::files()) ? 'nullable|string|required_without_all:blocks,files' : 'nullable|string|required_without:blocks',
            'blocks' => 'nullable|array',
            'files' => Features::enabled(Features::files()) ? 'nullable|array|min:1|max:'.config('chat-options.files.max_files', 10) : 'prohibited',
            'files.*' => 'required|integer|distinct',
        ]);

        if (isset($validated['blocks'])) {
            MessageBlocks::validate($validated['blocks']);
        }

        $routeRoom = $request->route('chatroom');
        if ($routeRoom !== null) {
            $routeRoomId = $routeRoom instanceof Chatroom ? $routeRoom->getKey() : $routeRoom;

            if ($validated['to_entity_type'] !== 'chatroom' || (string) $validated['to_entity_id'] !== (string) $routeRoomId) {
                throw ValidationException::withMessages(['to_entity_id' => 'The target must match the chatroom in the URL.']);
            }
        }

        $model = $request->to_entity_type === 'chatroom'
            ? Chatroom::findOrFail($request->to_entity_id)
            : ChatParticipant::findOrFail($request->to_entity_id);

        $messageConfiguration = ['files' => $validated['files'] ?? []];
        if (isset($validated['blocks'])) {
            $messageConfiguration['blocks'] = $validated['blocks'];
        }

        try {
            $message = $user->sendMessageTo(
                recipient: $model,
                message: $validated['message'] ?? '',
                messageConfiguration: $messageConfiguration,
            );

            if (Features::enabled(Features::files())) {
                $message->load('files');
            }

            return response()->json(new MessageResource($message), 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to send message', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Show a single message
     */
    public function showMessage(Request $request, int $message): MessageResource
    {
        $user = $this->participantUser($request);

        $found = ChatMessage::query()
            ->visibleTo($user)
            ->with($this->messageRelations())
            ->findOrFail($message);

        return new MessageResource($found);
    }

    public function updateMessage(Request $request, int $message): JsonResponse
    {
        $user = $this->participantUser($request);
        $found = ChatMessage::query()->visibleTo($user)->withoutTrashed()->findOrFail($message);
        abort_unless($found->chatroom->hasParticipant($user), 403);
        Gate::forUser($user)->authorize('le-chat.update-message', $found);
        $validated = $request->validate([
            'version' => 'required|integer|min:1',
            'message' => 'nullable|string|required_without:blocks',
            'blocks' => 'nullable|array',
        ]);

        if (isset($validated['blocks'])) {
            MessageBlocks::validate($validated['blocks']);
        }

        if (! $found->updateContent($validated['version'], array_intersect_key($validated, array_flip(['message', 'blocks'])))) {
            return response()->json(['error' => 'Message version conflict'], 409);
        }

        if (Features::enabled(Features::files())) {
            $found->load('files');
        }

        return response()->json(new MessageResource($found));
    }

    public function showApproval(Request $request, ChatApprovalBatch $batch): JsonResponse
    {
        $user = $this->participantUser($request);

        return response()->json(['id' => $batch->id, 'status' => $batch->status, 'approvals' => app(ApprovalBatches::class)->details($batch, $user)]);
    }

    public function decideApproval(Request $request, ChatApprovalBatch $batch): JsonResponse
    {
        $user = $this->participantUser($request);
        $validated = $request->validate([
            'idempotency_key' => 'required|string|max:255',
            'decisions' => 'required|array|min:1',
            'decisions.*.action' => ['required', Rule::in(['approve', 'reject'])],
            'decisions.*.result' => 'nullable|string|max:3000',
        ]);
        $result = app(ApprovalBatches::class)->decide($batch, $user, $validated['decisions'], $validated['idempotency_key']);

        return response()->json([
            'id' => (int) $result->getKey(),
            'status' => (string) $result->status,
            'failure_reason' => $result->failure_reason === null ? null : (string) $result->failure_reason,
        ]);
    }

    public function interactWithMessage(Request $request): Response
    {
        $user = $this->participantUser($request);
        $validated = $request->validate([
            'message_id' => 'required|integer',
            'block_id' => 'required|string|max:255',
            'action_id' => 'required|string|max:255',
            'value' => 'nullable',
        ]);
        if (strlen(json_encode($validated['value'] ?? null, JSON_THROW_ON_ERROR)) > 8192) {
            throw ValidationException::withMessages(['value' => 'The interaction value is too large.']);
        }
        $message = ChatMessage::query()->findOrFail($validated['message_id']);
        $message->getConnection()->transaction(function () use ($user, $validated): void {
            $message = ChatMessage::query()->visibleTo($user)->withoutTrashed()->with('approvalBatch')->lockForUpdate()->findOrFail($validated['message_id']);
            abort_unless($message->chatroom->hasParticipant($user), 403);
            Gate::forUser($user)->authorize('le-chat.interact-with-message', $message);
            $actionBlock = collect($message->blocks ?? [])->first(fn (array $block): bool => $block['type'] === 'actions' && $block['block_id'] === $validated['block_id']);
            if ($actionBlock === null || ! collect($actionBlock['elements'])->contains(fn (array $element): bool => $element['action_id'] === $validated['action_id']) || ($message->approvalBatch !== null && $validated['block_id'] === 'approval-'.$message->approvalBatch->id.'-actions')) {
                throw ValidationException::withMessages(['action_id' => 'The action is not available.']);
            }

            BlockInteracted::dispatch($message, $user, $validated['block_id'], $validated['action_id'], $validated['value'] ?? null);
        });

        return response()->noContent();
    }

    /**
     * Mark the chatroom as read.
     */
    public function markAsRead(Request $request, Chatroom $chatroom): JsonResponse
    {
        $user = $this->participantUser($request);

        $this->abortUnlessHasOrHadParticipant($chatroom, $user);

        if (! $chatroom->markAsReadBy($user)) {
            return response()->json(['error' => 'Failed to mark chatroom as read'], 400);
        }

        return response()->json(['message' => 'Chatroom marked as read'], 200);
    }

    /**
     * Resolve the authenticated chat participant or short-circuit with a 400.
     */
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

    private function messageRelations(): array
    {
        return ['sender', 'approvalBatch', ...(Features::enabled(Features::files()) ? ['files'] : [])];
    }

    /**
     * Short-circuit with a 403 unless the user is or was in the chatroom.
     */
    private function abortUnlessHasOrHadParticipant(Chatroom $chatroom, ChatParticipantInterface $user): void
    {
        if (! $chatroom->hasOrHadParticipant($user)) {
            throw new HttpResponseException(response()->json(['error' => 'Unauthorized'], 403));
        }
    }
}
