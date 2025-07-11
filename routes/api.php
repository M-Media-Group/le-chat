<?php

use Illuminate\Support\Facades\Route;
use Mmedia\LaravelChat\Http\Controllers\ChatroomController;

Route::group(['middleware' => 'auth:sanctum'], function () {
    Route::apiResource('/chatrooms', ChatroomController::class)
        ->names('chatrooms');
});
