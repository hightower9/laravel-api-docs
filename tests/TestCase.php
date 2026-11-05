<?php

namespace Souqbox\ApiDocs\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Souqbox\ApiDocs\ApiDocsServiceProvider;
use Souqbox\ApiDocs\Tests\Fixtures\DemoController;

/**
 * Boots a Laravel app with the package, one demo portal and fixture routes.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Output folder used by the tests.
     */
    protected const PATH = 'docs-testing';

    /**
     * Get the package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [ApiDocsServiceProvider::class];
    }

    /**
     * Configure the demo portal.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('api-docs.enabled', true);
        $app['config']->set('api-docs.path', self::PATH);
        $app['config']->set('api-docs.overlay_path', __DIR__ . '/Fixtures/overlays');
        $app['config']->set('api-docs.portals', [
            'demo' => [
                'title'       => 'Demo API',
                'prefixes'    => ['api/demo'],
                'guard'       => 'sanctum',
                'controllers' => 'Souqbox\ApiDocs\Tests\Fixtures',
                'login'       => 'api/demo/login',
                'logout'      => 'api/demo/logout',
                'token_path'  => 'token',
                'api_key'     => [
                    'scheme'      => 'deviceKey',
                    'header'      => 'X-DEVICE-KEY',
                    'description' => 'Key issued to the device.',
                    'middleware'  => ['device'],
                ],
            ],
        ]);
    }

    /**
     * Register the fixture routes.
     */
    protected function defineRoutes($router): void
    {
        Route::prefix('api/demo')->group(function () {
            Route::post('login', [DemoController::class, 'login']);
            Route::post('logout', [DemoController::class, 'logout'])->middleware('auth:sanctum');
            Route::get('items', [DemoController::class, 'index'])->middleware('auth:sanctum');
            Route::get('device', [DemoController::class, 'device'])->middleware('device');
        });
    }
}
