<?php

namespace DirectoryTree\Progress\Middleware;

use Closure;
use DirectoryTree\Progress\Models\Progress;

class SkipIfProgressFinished
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        public Progress $progress
    ) {}

    /**
     * Skip the job if the progress has finished.
     */
    public function handle(object $job, Closure $next): mixed
    {
        $this->progress->refresh();

        return $this->progress->finished() ? null : $next($job);
    }
}
