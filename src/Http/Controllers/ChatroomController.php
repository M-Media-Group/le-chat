<?php

namespace Mmedia\LeChat\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Http\Resources\ChatroomResource;
use Mmedia\LeChat\Http\Resources\MessageResource;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

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
            $query->visibleTo($user)->with('sender');
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

        $request->validate([
            'to_entity_type' => 'required|string|in:chatroom,chat_participant',
            'to_entity_id' => 'required|integer',
            'message' => 'required|string',
        ]);

        $model = $request->to_entity_type === 'chatroom'
            ? Chatroom::findOrFail($request->to_entity_id)
            : ChatParticipant::findOrFail($request->to_entity_id);

        try {
            $message = $user->sendMessageTo(
                $model,
                $request->message
            );

            return response()->json(new MessageResource($message), 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to send message', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Show a single message
     */
    public function showMessage(Request $request, Chatroom $chatroom, int $message): MessageResource
    {
        $user = $this->participantUser($request);

        $this->abortUnlessHasOrHadParticipant($chatroom, $user);

        $found = $chatroom->messages()
            ->visibleTo($user)
            ->with('sender')
            ->findOrFail($message);

        return new MessageResource($found);
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
