<?php

namespace DirectoryTree\Progress\Queue;

use DirectoryTree\Progress\Contracts\TracksRun;
use DirectoryTree\Progress\Middleware\TrackRun;
use DirectoryTree\Progress\Models\Run;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use WeakMap;

class RunTracker
{
    /**
     * The runs handled during the current queue executions.
     *
     * @var WeakMap<Job, Run>
     */
    protected WeakMap $runs;

    /**
     * Create a new run tracker.
     */
    public function __construct()
    {
        $this->runs = new WeakMap;
    }

    /**
     * Attach the run before the job is serialized onto the queue.
     */
    public function createPayload(?string $connection, ?string $queue, array $payload): array
    {
        $job = $payload['data']['command'] ?? null;

        if (! $job instanceof TracksRun) {
            return [];
        }

        $run = $job->getRun() ?? $job->createRun();

        $job->setRun($run);

        if (! in_array(TrackRun::class, $job->middleware ?? [], true)) {
            $job->middleware[] = TrackRun::class;
        }

        return [
            'progress' => [
                'class' => $run::class,
                'id' => $run->getKey(),
                'connection' => $run->getConnectionName(),
            ],
        ];
    }

    /**
     * Remember that the job reached the end of its handler.
     */
    public function handled(Job $job, Run $run): void
    {
        $this->runs[$job] = $run;
    }

    /**
     * Complete the run after the entire middleware pipeline succeeds.
     */
    public function processed(JobProcessed $event): void
    {
        $run = $this->runs[$event->job] ?? null;

        unset($this->runs[$event->job]);

        if ($run && ! $event->job->isReleased() && ! $event->job->hasFailed()) {
            $run->complete();
        }
    }

    /**
     * Fail the run when the queue job permanently fails.
     */
    public function failed(JobFailed $event): void
    {
        unset($this->runs[$event->job]);

        $run = $event->job->payload()['progress'] ?? null;

        if (! $run) {
            return;
        }

        (new $run['class'])->setConnection($run['connection'])
            ->newQueryForRestoration($run['id'])
            ->useWritePdo()
            ->first()
            ?->fail($event->exception);
    }
}
