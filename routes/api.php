<?php

use Illuminate\Support\Facades\Route;
use Laravel\Ai\Contracts\ConversationStore;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Http\Controllers\ChatFileController;
use Mmedia\LeChat\Http\Controllers\ChatroomController;

$controller = config('chat-options.routes.controller', ChatroomController::class);
$fileController = config('chat-options.routes.file_controller', ChatFileController::class);

Route::group(['middleware' => config('chat-options.routes.middleware'), 'prefix' => config('chat-options.routes.prefix')], function () use ($controller, $fileController) {
    if (Features::enabled(Features::files())) {
        Route::post('/files/{file}/content', [$fileController, 'uploadContent'])
            ->whereNumber('file')->name('chat.files.content');
        Route::patch('/files/{file}', [$fileController, 'update'])
            ->whereNumber('file')->name('chat.files.update');
        Route::apiResource('files', $fileController)
            ->only(['store', 'show'])
            ->names('chat.files')
            ->whereNumber('file');
    }
    Route::apiResource('/chatrooms', $controller)
        ->names('chatrooms');

    Route::get('/messages/{message}', [$controller, 'showMessage'])
        ->whereNumber('message')
        ->name('messages.show');

    Route::patch('/messages/{message}', [$controller, 'updateMessage'])
        ->whereNumber('message')
        ->name('messages.update');

    if (Features::enabled(Features::aiSdkIntegration()) && interface_exists(ConversationStore::class)) {
        Route::get('/approvals/{batch}', [$controller, 'showApproval'])
            ->whereNumber('batch')->name('approvals.show');
        Route::post('/approvals/{batch}', [$controller, 'decideApproval'])
            ->whereNumber('batch')->name('approvals.decide');
    }

    Route::post('/interactions', [$controller, 'interactWithMessage'])
        ->name('interactions.store');

    Route::post('/chatrooms/{chatroom}/mark-as-read', [$controller, 'markAsRead'])
        ->name('chatrooms.markAsRead');

    Route::post('/messages', [$controller, 'storeMessage']);
});
