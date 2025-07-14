<?php

use Illuminate\Support\Facades\Route;
use Mmedia\LaravelChat\Http\Controllers\ChatroomController;

Route::group(['middleware' => ['api', 'auth:sanctum'], 'prefix' => 'api'], function () {
    Route::apiResource('/chatrooms', ChatroomController::class)
        ->names('chatrooms');

    Route::post('/chatrooms/{chatroom}/messages', [ChatroomController::class, 'storeMessage']);

    Route::post('/chatrooms/{chatroom}/mark-as-read', [ChatroomController::class, 'markAsRead'])
        ->name('chatrooms.markAsRead');

    Route::post('/messages', [ChatroomController::class, 'storeMessage']);
});
