<?php

namespace Mmedia\LaravelChat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Mmedia\LaravelChat\Http\Resources\ChatroomResource;
use Mmedia\LaravelChat\Models\ChatParticipant;
use Mmedia\LaravelChat\Models\Chatroom;

class ChatroomController extends Controller
{
    /**
     * Display a listing of the chatrooms.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $chatrooms = Chatroom::havingParticipants([$user], true)
            ->with([
                'participants',
                'latestMessage' => function ($query) use ($user) {
                    $query->canBeReadByParticipant($user)->with('sender');
                },
            ])
            ->withUnreadMessagesCountFor($user)
            ->get();

        return ChatroomResource::collection($chatrooms);
    }

    /**
     * Show the form for creating a new chatroom.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, Chatroom $chatroom)
    {
        $user = $request->user();

        if (! $chatroom->hasOrHadParticipant($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $chatroom->load(['participants', 'messages' => function ($query) use ($user) {
            $query->canBeReadByParticipant($user)->with('sender');
        }]);

        return new ChatroomResource($chatroom);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $chatroom = Chatroom::create($request->only('name', 'description'));

        $user = $request->user();
        $chatroom->addParticipant($user, 'admin');

        return response()->json($chatroom, 201);
    }

    public function storeMessage(Request $request)
    {
        $request->validate([
            'to_entity_type' => 'required|string',
            'to_entity_id' => 'required|integer',
            'message' => 'required|string',
        ]);

        $model = $request->to_entity_type === 'chatroom'
            ? Chatroom::findOrFail($request->to_entity_id)
            : ChatParticipant::findOrFail($request->to_entity_id);

        try {
            $request->user()->sendMessageTo(
                $model,
                $request->message
            );

            return response()->json(['message' => 'Message sent successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to send message', 'message' => $e->getMessage()], 400);
        }
    }

    public function markAsRead(Request $request, Chatroom $chatroom)
    {
        $user = $request->user();

        if (! $chatroom->hasOrHadParticipant($user)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $chatroom->markAsReadBy($user)) {
            return response()->json(['error' => 'Failed to mark chatroom as read'], 400);
        }

        return response()->json(['message' => 'Chatroom marked as read'], 200);
    }
}
