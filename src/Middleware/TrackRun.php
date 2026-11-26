<?php

namespace DirectoryTree\Progress\Middleware;

use Closure;
use DirectoryTree\Progress\Contracts\TracksRun;
use DirectoryTree\Progress\Queue\RunTracker;

class TrackRun
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected RunTracker $tracker
    ) {}

    /**
     * Manage the run owned by the queued job.
     */
    public function handle(TracksRun $job, Closure $next): mixed
    {
        $run = $job->getRun()->restartIfFailed();

        if ($run->finished()) {
            return null;
        }

        if (is_null($run->started_at)) {
            $run->forceFill([
                'started_at' => $run->freshTimestamp(),
            ])->save();
        }

        $result = $next($job);

        $this->tracker->handled($job->job, $run);

        return $result;
    }
}
