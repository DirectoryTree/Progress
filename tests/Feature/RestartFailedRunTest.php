<?php

use DirectoryTree\Progress\Enums\RunStatus;
use DirectoryTree\Progress\Middleware\RestartFailedRun;
use DirectoryTree\Progress\Middleware\SkipIfRunFinished;
use DirectoryTree\Progress\Models\Run;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

it('restarts failed run before handling jobs', function () {
    $run = Run::start(name: 'Sync contacts', total: 2, metadata: ['source' => 'crm']);
    $run->missed(new RuntimeException('Unable to sync contact'), metadata: ['row' => 1]);
    $run->fail(new RuntimeException('Unable to finish sync'));
    $run->load('items');

    $job = new MiddlewareRunJob($run);
    $transactionLevel = DB::transactionLevel();

    $result = (new RestartFailedRun($run))->handle($job, function ($command) use ($job, $transactionLevel) {
        expect($command)->toBe($job)
            ->and($command->run)
            ->status->toBe(RunStatus::Running)
            ->processed_items->toBe(0)
            ->failed_items->toBe(0)
            ->exception->toBeNull()
            ->metadata->toBe(['source' => 'crm'])
            ->total_items->toBe(2)
            ->and($command->run->items)->toHaveCount(0)
            ->and(DB::transactionLevel())->toBe($transactionLevel);

        $command->run->advance();

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($run->fresh()->processed_items)->toBe(1)
        ->and($run->fresh()->status)->toBe(RunStatus::Running)
        ->and(Run::query()->count())->toBe(1);
});

it('preserves progress for pending and running progress', function (RunStatus $status) {
    $run = $status === RunStatus::Pending ? Run::query()->create() : Run::start();
    $run->missed(metadata: ['row' => 1]);
    $startedAt = $run->started_at;

    $result = (new RestartFailedRun($run))->handle(new MiddlewareRunJob($run), function ($job) use ($status, $startedAt) {
        expect($job->run)
            ->status->toBe($status)
            ->processed_items->toBe(1)
            ->failed_items->toBe(1)
            ->started_at->toEqual($startedAt)
            ->and($job->run->items)->toHaveCount(1);

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($run->fresh()->status)->toBe($status);
})->with([RunStatus::Pending, RunStatus::Running]);

it('continues handling completed and cancelled progress without restarting them', function (string $transition, RunStatus $status) {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $middleware = new RestartFailedRun($run);

    $run->fresh()->{$transition}();

    $job = new MiddlewareRunJob($run);

    $result = $middleware->handle($job, function ($command) use ($job) {
        expect($command)->toBe($job);

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($run)
        ->status->toBe($status)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->and($run->items)->toHaveCount(1);
})->with([
    'completed' => ['complete', RunStatus::Completed],
    'cancelled' => ['cancel', RunStatus::Cancelled],
]);

it('detects failures recorded after the progress was loaded', function () {
    $run = Run::start();
    $middleware = new RestartFailedRun($run);

    $persisted = $run->fresh();
    $persisted->missed(metadata: ['row' => 1]);
    $persisted->fail();

    $middleware->handle(new MiddlewareRunJob($run), function ($job) {
        expect($job->run)
            ->status->toBe(RunStatus::Running)
            ->processed_items->toBe(0)
            ->failed_items->toBe(0)
            ->and($job->run->items)->toHaveCount(0);
    });
});

it('does not reset progress again from a stale failed reference', function () {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $run->fail();

    $staleRun = $run->fresh();
    $staleRun->load('items');

    (new RestartFailedRun($run))->handle(new MiddlewareRunJob($run), function ($job) {
        $job->run->advance();
    });

    (new RestartFailedRun($staleRun))->handle(new MiddlewareRunJob($staleRun), function ($job) {
        expect($job->run)
            ->status->toBe(RunStatus::Running)
            ->processed_items->toBe(1)
            ->failed_items->toBe(0)
            ->and($job->run->items)->toHaveCount(0);

        $job->run->advance();
    });

    expect($run->fresh()->processed_items)->toBe(2);
});

it('propagates job exceptions without failing or resetting active progress', function () {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);

    expect(fn () => (new RestartFailedRun($run))->handle(new MiddlewareRunJob($run), function ($job) {
        $job->run->advance();

        throw new RuntimeException('Unable to finish sync');
    }))->toThrow(RuntimeException::class, 'Unable to finish sync');

    expect($run->refresh())
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(2)
        ->failed_items->toBe(1)
        ->exception->toBeNull()
        ->and($run->items)->toHaveCount(1);
});

it('preserves automatic queue progress and restarts after queue retry', function () {
    config([
        'queue.default' => 'database',
        'progress-testing.fail' => true,
    ]);

    $run = Run::start(total: 3);
    MiddlewareRunJob::dispatch($run);

    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 2])
        ->assertExitCode(0);

    expect($run->refresh())
        ->status->toBe(RunStatus::Failed)
        ->processed_items->toBe(2)
        ->exception->toContain('Unable to finish sync')
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    config(['progress-testing.fail' => false]);

    artisan('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]])
        ->assertExitCode(0);

    expect($run->fresh()->status)->toBe(RunStatus::Failed);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->processed_items->toBe(1)
        ->failed_at->toBeNull()
        ->exception->toBeNull()
        ->and(Run::query()->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('restarts failed run when original payloads are pushed directly onto the queue', function () {
    config(['queue.default' => 'database']);

    $run = Run::start();
    MiddlewareRunJob::dispatch($run);

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    DB::table('jobs')->delete();

    $run->missed(metadata: ['row' => 1]);
    $run->fail();

    $payload['retry_of'] = $payload['uuid'];
    $payload['uuid'] = (string) Str::uuid();
    $payload['attempts'] = 0;

    Queue::connection('database')->pushRaw(json_encode($payload), 'default');

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(0)
        ->failed_at->toBeNull()
        ->and($run->items)->toHaveCount(0)
        ->and(Run::query()->count())->toBe(1);
});

it('skips completed and cancelled jobs when both middleware are registered', function (string $transition, RunStatus $status) {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $run->{$transition}();

    $attributes = $run->fresh()->getAttributes();

    Queue::connection('sync')->push(new MiddlewareRunJob($run));

    expect($run->fresh()->getAttributes())->toBe($attributes)
        ->and($run->fresh()->status)->toBe($status)
        ->and($run->items)->toHaveCount(1);
})->with([
    'completed' => ['complete', RunStatus::Completed],
    'cancelled' => ['cancel', RunStatus::Cancelled],
]);

class MiddlewareRunJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Run $run
    ) {}

    public function middleware(): array
    {
        return [
            new RestartFailedRun($this->run),
            new SkipIfRunFinished($this->run),
        ];
    }

    public function handle(): void
    {
        $this->run->advance();

        if (config('progress-testing.fail', false)) {
            throw new RuntimeException('Unable to finish sync');
        }

        $this->run->complete();
    }

    public function failed(?Throwable $exception): void
    {
        $this->run->fail($exception);
    }
}
