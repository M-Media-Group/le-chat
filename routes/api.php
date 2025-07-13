<?php

use Illuminate\Support\Facades\Route;
use Mmedia\LaravelChat\Http\Controllers\ChatroomController;

Route::group(['middleware' => ['api', 'auth:sanctum'], 'prefix' => 'api'], function () {
    Route::apiResource('/chatrooms', ChatroomController::class)
        ->names('chatrooms');

    Route::post('/messages', [ChatroomController::class, 'storeMessage'])
        ->name('chatrooms.messages.store');
});
