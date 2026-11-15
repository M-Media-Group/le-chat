<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Contracts\ConversationStore;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\Http\Controllers\ChatFileController;
use Mmedia\LeChat\Http\Controllers\ChatroomController;

class CustomRouteChatroomController extends ChatroomController {}

class CustomRouteChatFileController extends ChatFileController {}

it('returns 401 for unauthenticated chatroom API requests', function () {
    config(['auth.guards.sanctum' => ['driver' => 'session', 'provider' => 'users']]);
    app('auth')->forgetGuards();

    $this->getJson(route('chatrooms.index'))->assertUnauthorized();
});

it('registers the API routes from the default package config', function () {
    expect(Route::has('chatrooms.index'))->toBeTrue()
        ->and(Route::has('chatrooms.markAsRead'))->toBeTrue()
        ->and(Route::has('messages.show'))->toBeTrue()
        ->and(Route::has('messages.update'))->toBeTrue()
        ->and(Route::has('approvals.show'))->toBeTrue()
        ->and(Route::has('interactions.store'))->toBeTrue()
        ->and(Route::has('chatrooms.messages.show'))->toBeFalse()
        ->and(Route::has('chatrooms.messages.update'))->toBeFalse()
        ->and(Route::has('chatrooms.messages.approvals.show'))->toBeFalse()
        ->and(Route::has('chatrooms.messages.approvals.decide'))->toBeFalse()
        ->and(Route::has('chatrooms.messages.interactions.store'))->toBeFalse()
        ->and(Route::getRoutes()->getByName('messages.show')->getControllerClass())->toBe(ChatroomController::class);
});

it('registers approval routes only when the AI SDK integration is enabled', function (bool $enabled) {
    $router = app('router');
    $original = $router->getRoutes();
    $router->setRoutes(new RouteCollection);
    config(['chat.features' => $enabled ? [Features::aiSdkIntegration()] : []]);

    try {
        require __DIR__.'/../routes/api.php';
        $router->getRoutes()->refreshNameLookups();
        $approvalsEnabled = $enabled && interface_exists(ConversationStore::class);

        expect(Route::has('approvals.show'))->toBe($approvalsEnabled)
            ->and(Route::has('approvals.decide'))->toBe($approvalsEnabled)
            ->and(Route::has('interactions.store'))->toBeTrue();
    } finally {
        $router->setRoutes($original);
    }
})->with([false, true]);

it('does not enable files in the package defaults', function () {
    $defaults = require __DIR__.'/../config/chat.php';

    expect($defaults['features'])->not->toContain(Features::files())
        ->and($defaults)->not->toHaveKey('files');
});

it('preserves configured route options when loading package defaults', function () {
    $options = ['middleware' => ['api', 'auth:sanctum'], 'prefix' => 'api/chat', 'controller' => CustomRouteChatroomController::class];
    config(['chat-options.routes' => $options]);

    require __DIR__.'/../config/chat.php';

    expect(config('chat-options.routes'))->toBe($options);
});

it('uses the configured controller and prefix for package routes', function () {
    $router = app('router');
    $original = $router->getRoutes();
    $router->setRoutes(new RouteCollection);
    config([
        'chat.features' => [Features::files()],
        'chat-options.routes' => ['middleware' => ['api', 'auth:sanctum'], 'prefix' => 'api/chat', 'controller' => CustomRouteChatroomController::class, 'file_controller' => CustomRouteChatFileController::class],
    ]);

    try {
        require __DIR__.'/../routes/api.php';
        $router->getRoutes()->refreshNameLookups();

        expect(Route::getRoutes()->getByName('chatrooms.index')->uri())->toBe('api/chat/chatrooms')
            ->and(Route::getRoutes()->getByName('chat.files.show')->uri())->toBe('api/chat/files/{file}')
            ->and(Route::getRoutes()->getByName('chat.files.content')->getControllerClass())->toBe(CustomRouteChatFileController::class)
            ->and(Route::getRoutes()->getByName('messages.show')->getControllerClass())->toBe(CustomRouteChatroomController::class);
    } finally {
        $router->setRoutes($original);
    }
});

it('registers file routes only when the feature is enabled', function (bool $enabled) {
    $router = app('router');
    $original = $router->getRoutes();
    $router->setRoutes(new RouteCollection);
    config(['chat.features' => $enabled ? [Features::files()] : []]);

    try {
        require __DIR__.'/../routes/api.php';
        $router->getRoutes()->refreshNameLookups();

        expect(Route::has('files.store'))->toBeFalse()
            ->and(Route::has('chat.files.show'))->toBe($enabled)
            ->and(Route::has('chat.files.store'))->toBe($enabled)
            ->and(Route::has('chat.files.content'))->toBe($enabled)
            ->and(Route::has('chat.files.update'))->toBe($enabled)
            ->and(Route::has('chatrooms.messages.files.download'))->toBeFalse()
            ->and(Route::has('messages.show'))->toBeTrue();
    } finally {
        $router->setRoutes($original);
    }
})->with([false, true]);
