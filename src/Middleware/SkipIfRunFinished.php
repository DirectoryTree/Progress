<?php

namespace DirectoryTree\Stride\Middleware;

use Closure;
use DirectoryTree\Stride\Models\Run;

class SkipIfRunFinished
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(public Run $run) {}

    /**
     * Skip the job if the run has finished.
     */
    public function handle(object $job, Closure $next): mixed
    {
        $this->run->refresh();

        return $this->run->finished() ? null : $next($job);
    }
}
