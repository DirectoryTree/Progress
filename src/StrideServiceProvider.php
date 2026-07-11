<?php

namespace DirectoryTree\Stride;

use Illuminate\Support\ServiceProvider;

class StrideServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'stride-migrations');
    }
}
