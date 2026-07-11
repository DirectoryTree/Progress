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
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'stride-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
