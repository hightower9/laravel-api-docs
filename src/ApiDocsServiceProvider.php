<?php

namespace Souqbox\ApiDocs;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Souqbox\ApiDocs\Console\ApiDocsClearCommand;
use Souqbox\ApiDocs\Console\ApiDocsGenerateCommand;
use Souqbox\ApiDocs\Http\ApiDocsController;

/**
 * Registers the config, views and commands, the /docs route and the "php artisan optimize" hook.
 */
class ApiDocsServiceProvider extends ServiceProvider
{
    /**
     * Merge the package config defaults.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/api-docs.php', 'api-docs');
    }

    /**
     * Register the package resources, and the /docs route when the docs are enabled.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'api-docs');
        $this->optimizes(optimize: 'api-docs:generate', key: 'api-docs');

        if ($this->app->runningInConsole()) {
            $this->commands([ApiDocsGenerateCommand::class, ApiDocsClearCommand::class]);
            $this->publishes([__DIR__ . '/../config/api-docs.php' => config_path('api-docs.php')], 'api-docs-config');
            $this->publishes([__DIR__ . '/../stubs/docs-api' => base_path('docs/api')], 'api-docs-overlays');
        }

        if (! config('api-docs.enabled') || $this->app->routesAreCached()) {
            return;
        }

        Route::get(config('api-docs.path'), ApiDocsController::class)->name('api-docs');
    }
}
