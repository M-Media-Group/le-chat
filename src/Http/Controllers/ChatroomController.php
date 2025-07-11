<?php

namespace Mmedia\LaravelChat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
        $chatrooms = Chatroom::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id);
        })->get();

        return response()->json($chatrooms);
    }

    /**
     * Show the form for creating a new chatroom.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return response()->json([
            'message' => 'Show form for creating a new chatroom',
        ]);
    }

    // Additional methods for store, show, edit, update, and destroy can be added here
}
