<?php

namespace Mmedia\LeChat\Tests;

use Dedoc\Scramble\ScrambleServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Mmedia\LeChat\Features;
use Mmedia\LeChat\LeChatServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(__DIR__.'/../database/migrations/*.stub') as $migrationFile) {
            (require $migrationFile)->up();
        }

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Mmedia\\LeChat\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LeChatServiceProvider::class,
            ScrambleServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('cache.default', 'array');
        config()->set('queue.default', 'sync');
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('chat.features', [
            Features::routes(['middleware' => ['api', 'auth:sanctum'], 'prefix' => 'api']),
            Features::aiSdkIntegration(['auto_respond' => true, 'typing_indicators' => true]),
            Features::files(),
        ]);

        // foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/database/migrations') as $migration) {
        //     (include $migration->getRealPath())->up();
        // }
    }
}
