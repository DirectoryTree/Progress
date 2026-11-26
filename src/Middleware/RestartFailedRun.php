<?php

namespace DirectoryTree\Progress\Middleware;

use Closure;
use DirectoryTree\Progress\Models\Run;

class RestartFailedRun
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        public Run $run
    ) {}

    /**
     * Restart failed run before processing the job.
     */
    public function handle(object $job, Closure $next): mixed
    {
        $this->run->restartIfFailed();

        return $next($job);
    }
}
