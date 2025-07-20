<?php

use Illuminate\Support\Facades\Route;
use Mmedia\LeChat\Http\Controllers\ChatroomController;

Route::group(['middleware' => config('chat-options.routes.middleware'), 'prefix' => config('chat-options.routes.prefix')], function () {
    Route::apiResource('/chatrooms', ChatroomController::class)
        ->names('chatrooms');

    Route::post('/chatrooms/{chatroom}/messages', [ChatroomController::class, 'storeMessage']);

    Route::post('/chatrooms/{chatroom}/mark-as-read', [ChatroomController::class, 'markAsRead'])
        ->name('chatrooms.markAsRead');

    Route::post('/messages', [ChatroomController::class, 'storeMessage']);
});
