<?php

declare(strict_types=1);

namespace FileHutch\Laravel;

use FileHutch\Client;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class FileHutchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/filehutch.php', 'filehutch');

        // Resolved on first use, so an app without a key still boots; the
        // ConfigurationError arrives when something actually needs FileHutch.
        $this->app->singleton(Client::class, static fn ($app) => new Client(
            apiKey: $app['config']->get('filehutch.api_key'),
            url: $app['config']->get('filehutch.url'),
            timeout: (float) $app['config']->get('filehutch.timeout', 30),
            userAgent: 'laravel/' . $app->version(),
        ));
        $this->app->alias(Client::class, 'filehutch');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/filehutch.php' => config_path('filehutch.php')], 'filehutch-config');
        }

        $uploads = $this->app['config']->get('filehutch.direct_uploads', []);
        if (($uploads['enabled'] ?? true) && !$this->app->routesAreCached()) {
            Route::prefix($uploads['prefix'] ?? 'file_hutch')
                ->middleware($uploads['middleware'] ?? ['web'])
                ->group(__DIR__ . '/../../routes/filehutch.php');
        }
    }
}
