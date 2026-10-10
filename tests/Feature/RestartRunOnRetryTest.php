<?php

use DirectoryTree\Stride\Enums\RunStatus;
use DirectoryTree\Stride\Middleware\RestartRunOnRetry;
use DirectoryTree\Stride\Models\Run;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

it('restarts failed runs before handling jobs', function () {
    $run = Run::start(name: 'Sync contacts', total: 2, metadata: ['source' => 'crm']);
    $run->missed(new RuntimeException('Unable to sync contact'), metadata: ['row' => 1]);
    $run->fail(new RuntimeException('Unable to finish sync'));
    $run->load('items');

    $job = new MiddlewareRunJob($run);
    $transactionLevel = DB::transactionLevel();

    $result = (new RestartRunOnRetry($run))->handle($job, function ($command) use ($job, $transactionLevel) {
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

        $command->run->record();

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($run->fresh()->processed_items)->toBe(1)
        ->and($run->fresh()->status)->toBe(RunStatus::Running)
        ->and(Run::query()->count())->toBe(1);
});

it('preserves progress for pending and running runs', function (RunStatus $status) {
    $run = $status === RunStatus::Pending ? Run::query()->create() : Run::start();
    $run->missed(metadata: ['row' => 1]);
    $startedAt = $run->started_at;

    $result = (new RestartRunOnRetry($run))->handle(new MiddlewareRunJob($run), function ($job) use ($status, $startedAt) {
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

it('skips completed and cancelled runs using their persisted state', function (string $transition, RunStatus $status) {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $middleware = new RestartRunOnRetry($run);

    $run->fresh()->{$transition}();

    $result = $middleware->handle(new MiddlewareRunJob($run), function () {
        throw new RuntimeException('The job should not execute');
    });

    expect($result)->toBeNull()
        ->and($run)
        ->status->toBe($status)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->and($run->items)->toHaveCount(1);
})->with([
    'completed' => ['complete', RunStatus::Completed],
    'cancelled' => ['cancel', RunStatus::Cancelled],
]);

it('detects failures recorded after the run was loaded', function () {
    $run = Run::start();
    $middleware = new RestartRunOnRetry($run);

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

it('does not reset a run again from a stale failed reference', function () {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $run->fail();

    $staleRun = $run->fresh();
    $staleRun->load('items');

    (new RestartRunOnRetry($run))->handle(new MiddlewareRunJob($run), function ($job) {
        $job->run->record();
    });

    (new RestartRunOnRetry($staleRun))->handle(new MiddlewareRunJob($staleRun), function ($job) {
        expect($job->run)
            ->status->toBe(RunStatus::Running)
            ->processed_items->toBe(1)
            ->failed_items->toBe(0)
            ->and($job->run->items)->toHaveCount(0);

        $job->run->record();
    });

    expect($run->fresh()->processed_items)->toBe(2);
});

it('propagates job exceptions without failing or resetting active runs', function () {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);

    expect(fn () => (new RestartRunOnRetry($run))->handle(new MiddlewareRunJob($run), function ($job) {
        $job->run->record();

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
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });

    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
        'queue.failed' => [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ],
        'stride-testing.fail' => true,
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

    config(['stride-testing.fail' => false]);
    Event::fake([JobRetryRequested::class]);

    artisan('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]])
        ->assertExitCode(0);

    Event::assertDispatched(JobRetryRequested::class);

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

it('restarts failed runs when original payloads are pushed directly onto the queue', function () {
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ]);

    $run = Run::start();
    MiddlewareRunJob::dispatch($run);

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    DB::table('jobs')->delete();

    $run->missed(metadata: ['row' => 1]);
    $run->fail();

    $payload['retry_of'] = $payload['uuid'];
    $payload['uuid'] = (string) Str::uuid();
    $payload['attempts'] = 0;

    Event::fake([JobRetryRequested::class]);

    Queue::connection('database')->pushRaw(json_encode($payload), 'default');

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    Event::assertNotDispatched(JobRetryRequested::class);

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(0)
        ->failed_at->toBeNull()
        ->and($run->items)->toHaveCount(0)
        ->and(Run::query()->count())->toBe(1);
});

class MiddlewareRunJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Run $run) {}

    public function middleware(): array
    {
        return [new RestartRunOnRetry($this->run)];
    }

    public function handle(): void
    {
        $this->run->record();

        if (config('stride-testing.fail', false)) {
            throw new RuntimeException('Unable to finish sync');
        }

        $this->run->complete();
    }

    public function failed(?Throwable $exception): void
    {
        $this->run->fail($exception);
    }
}
