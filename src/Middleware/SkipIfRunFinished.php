<?php

namespace DirectoryTree\Progress\Middleware;

use Closure;
use DirectoryTree\Progress\Models\Run;

class SkipIfRunFinished
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        public Run $run
    ) {}

    /**
     * Skip the job if the run has finished.
     */
    public function handle(object $job, Closure $next): mixed
    {
        $this->run->refresh();

        return $this->run->isFinished() ? null : $next($job);
    }
}
