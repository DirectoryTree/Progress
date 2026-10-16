<?php

namespace DirectoryTree\Progress\Middleware;

use Closure;
use DirectoryTree\Progress\Contracts\TracksProgress;
use DirectoryTree\Progress\Queue\ProgressTracker;

class TrackProgress
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ProgressTracker $tracker
    ) {}

    /**
     * Manage the progress owned by the queued job.
     */
    public function handle(TracksProgress $job, Closure $next): mixed
    {
        $progress = $job->getProgress()->restartIfFailed();

        if ($progress->finished()) {
            return null;
        }

        if (is_null($progress->started_at)) {
            $progress->forceFill(['started_at' => $progress->freshTimestamp()])->save();
        }

        $result = $next($job);

        $this->tracker->handled($job->job, $progress);

        return $result;
    }
}
