<?php

namespace DirectoryTree\Progress\Middleware;

use Closure;
use DirectoryTree\Progress\Models\Progress;

class RestartFailedProgress
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        public Progress $progress
    ) {}

    /**
     * Restart failed progress before processing the job.
     */
    public function handle(object $job, Closure $next): mixed
    {
        $this->progress->restartIfFailed();

        return $next($job);
    }
}
