<?php

namespace DirectoryTree\Progress;

use DirectoryTree\Progress\Queue\RunTracker;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Queue;
use Illuminate\Support\ServiceProvider;

class ProgressServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->app->singleton(RunTracker::class);
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $tracker = $this->app->make(RunTracker::class);

        Queue::createPayloadUsing([$tracker, 'createPayload']);

        $this->app['events']->listen(JobFailed::class, [$tracker, 'failed']);
        $this->app['events']->listen(JobProcessed::class, [$tracker, 'processed']);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'progress-migrations');
    }
}
