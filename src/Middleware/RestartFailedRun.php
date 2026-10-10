<?php

namespace DirectoryTree\Stride\Middleware;

use Closure;
use DirectoryTree\Stride\Models\Run;

class RestartFailedRun
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        public Run $run
    ) {}

    /**
     * Restart a failed run before processing the job.
     */
    public function handle(object $job, Closure $next): mixed
    {
        $this->run->restartIfFailed();

        return $next($job);
    }
}
