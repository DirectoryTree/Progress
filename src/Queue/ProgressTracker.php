<?php

namespace DirectoryTree\Progress\Queue;

use DirectoryTree\Progress\Contracts\TracksProgress;
use DirectoryTree\Progress\Middleware\TrackProgress;
use DirectoryTree\Progress\Models\Progress;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use WeakMap;

class ProgressTracker
{
    /**
     * The progress handled during the current queue executions.
     *
     * @var WeakMap<Job, Progress>
     */
    protected WeakMap $progress;

    /**
     * Create a new progress tracker.
     */
    public function __construct()
    {
        $this->progress = new WeakMap;
    }

    /**
     * Attach the progress before the job is serialized onto the queue.
     */
    public function createPayload(?string $connection, ?string $queue, array $payload): array
    {
        $job = $payload['data']['command'] ?? null;

        if (! $job instanceof TracksProgress) {
            return [];
        }

        $progress = $job->getProgress() ?? $job->createProgress();

        $job->setProgress($progress);

        if (! in_array(TrackProgress::class, $job->middleware ?? [], true)) {
            $job->middleware[] = TrackProgress::class;
        }

        return [
            'progress' => [
                'class' => $progress::class,
                'id' => $progress->getKey(),
                'connection' => $progress->getConnectionName(),
            ],
        ];
    }

    /**
     * Remember that the job reached the end of its handler.
     */
    public function handled(Job $job, Progress $progress): void
    {
        $this->progress[$job] = $progress;
    }

    /**
     * Complete the progress after the entire middleware pipeline succeeds.
     */
    public function processed(JobProcessed $event): void
    {
        $progress = $this->progress[$event->job] ?? null;

        unset($this->progress[$event->job]);

        if ($progress && ! $event->job->isReleased() && ! $event->job->hasFailed()) {
            $progress->complete();
        }
    }

    /**
     * Fail the progress when the queue job permanently fails.
     */
    public function failed(JobFailed $event): void
    {
        unset($this->progress[$event->job]);

        $progress = $event->job->payload()['progress'] ?? null;

        if (! $progress) {
            return;
        }

        (new $progress['class'])->setConnection($progress['connection'])
            ->newQueryForRestoration($progress['id'])
            ->useWritePdo()
            ->first()
            ?->fail($event->exception);
    }
}
