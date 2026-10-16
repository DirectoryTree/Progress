<?php

use DirectoryTree\Progress\Enums\ProgressStatus;
use DirectoryTree\Progress\Middleware\RestartFailedProgress;
use DirectoryTree\Progress\Middleware\SkipIfProgressFinished;
use DirectoryTree\Progress\Models\Progress;
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

it('restarts failed progress before handling jobs', function () {
    $progress = Progress::start(name: 'Sync contacts', total: 2, metadata: ['source' => 'crm']);
    $progress->missed(new RuntimeException('Unable to sync contact'), metadata: ['row' => 1]);
    $progress->fail(new RuntimeException('Unable to finish sync'));
    $progress->load('items');

    $job = new MiddlewareProgressJob($progress);
    $transactionLevel = DB::transactionLevel();

    $result = (new RestartFailedProgress($progress))->handle($job, function ($command) use ($job, $transactionLevel) {
        expect($command)->toBe($job)
            ->and($command->progress)
            ->status->toBe(ProgressStatus::Running)
            ->processed_items->toBe(0)
            ->failed_items->toBe(0)
            ->exception->toBeNull()
            ->metadata->toBe(['source' => 'crm'])
            ->total_items->toBe(2)
            ->and($command->progress->items)->toHaveCount(0)
            ->and(DB::transactionLevel())->toBe($transactionLevel);

        $command->progress->advance();

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($progress->fresh()->processed_items)->toBe(1)
        ->and($progress->fresh()->status)->toBe(ProgressStatus::Running)
        ->and(Progress::query()->count())->toBe(1);
});

it('preserves progress for pending and running progress', function (ProgressStatus $status) {
    $progress = $status === ProgressStatus::Pending ? Progress::query()->create() : Progress::start();
    $progress->missed(metadata: ['row' => 1]);
    $startedAt = $progress->started_at;

    $result = (new RestartFailedProgress($progress))->handle(new MiddlewareProgressJob($progress), function ($job) use ($status, $startedAt) {
        expect($job->progress)
            ->status->toBe($status)
            ->processed_items->toBe(1)
            ->failed_items->toBe(1)
            ->started_at->toEqual($startedAt)
            ->and($job->progress->items)->toHaveCount(1);

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($progress->fresh()->status)->toBe($status);
})->with([ProgressStatus::Pending, ProgressStatus::Running]);

it('continues handling completed and cancelled progress without restarting them', function (string $transition, ProgressStatus $status) {
    $progress = Progress::start();
    $progress->missed(metadata: ['row' => 1]);
    $middleware = new RestartFailedProgress($progress);

    $progress->fresh()->{$transition}();

    $job = new MiddlewareProgressJob($progress);

    $result = $middleware->handle($job, function ($command) use ($job) {
        expect($command)->toBe($job);

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($progress)
        ->status->toBe($status)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->and($progress->items)->toHaveCount(1);
})->with([
    'completed' => ['complete', ProgressStatus::Completed],
    'cancelled' => ['cancel', ProgressStatus::Cancelled],
]);

it('detects failures recorded after the progress was loaded', function () {
    $progress = Progress::start();
    $middleware = new RestartFailedProgress($progress);

    $persisted = $progress->fresh();
    $persisted->missed(metadata: ['row' => 1]);
    $persisted->fail();

    $middleware->handle(new MiddlewareProgressJob($progress), function ($job) {
        expect($job->progress)
            ->status->toBe(ProgressStatus::Running)
            ->processed_items->toBe(0)
            ->failed_items->toBe(0)
            ->and($job->progress->items)->toHaveCount(0);
    });
});

it('does not reset progress again from a stale failed reference', function () {
    $progress = Progress::start();
    $progress->missed(metadata: ['row' => 1]);
    $progress->fail();

    $staleProgress = $progress->fresh();
    $staleProgress->load('items');

    (new RestartFailedProgress($progress))->handle(new MiddlewareProgressJob($progress), function ($job) {
        $job->progress->advance();
    });

    (new RestartFailedProgress($staleProgress))->handle(new MiddlewareProgressJob($staleProgress), function ($job) {
        expect($job->progress)
            ->status->toBe(ProgressStatus::Running)
            ->processed_items->toBe(1)
            ->failed_items->toBe(0)
            ->and($job->progress->items)->toHaveCount(0);

        $job->progress->advance();
    });

    expect($progress->fresh()->processed_items)->toBe(2);
});

it('propagates job exceptions without failing or resetting active progress', function () {
    $progress = Progress::start();
    $progress->missed(metadata: ['row' => 1]);

    expect(fn () => (new RestartFailedProgress($progress))->handle(new MiddlewareProgressJob($progress), function ($job) {
        $job->progress->advance();

        throw new RuntimeException('Unable to finish sync');
    }))->toThrow(RuntimeException::class, 'Unable to finish sync');

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Running)
        ->processed_items->toBe(2)
        ->failed_items->toBe(1)
        ->exception->toBeNull()
        ->and($progress->items)->toHaveCount(1);
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
        'progress-testing.fail' => true,
    ]);

    $progress = Progress::start(total: 3);
    MiddlewareProgressJob::dispatch($progress);

    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 2])
        ->assertExitCode(0);

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Failed)
        ->processed_items->toBe(2)
        ->exception->toContain('Unable to finish sync')
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    config(['progress-testing.fail' => false]);
    Event::fake([JobRetryRequested::class]);

    artisan('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]])
        ->assertExitCode(0);

    Event::assertDispatched(JobRetryRequested::class);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Completed)
        ->processed_items->toBe(1)
        ->failed_at->toBeNull()
        ->exception->toBeNull()
        ->and(Progress::query()->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('restarts failed progress when original payloads are pushed directly onto the queue', function () {
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

    $progress = Progress::start();
    MiddlewareProgressJob::dispatch($progress);

    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    DB::table('jobs')->delete();

    $progress->missed(metadata: ['row' => 1]);
    $progress->fail();

    $payload['retry_of'] = $payload['uuid'];
    $payload['uuid'] = (string) Str::uuid();
    $payload['attempts'] = 0;

    Event::fake([JobRetryRequested::class]);

    Queue::connection('database')->pushRaw(json_encode($payload), 'default');

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    Event::assertNotDispatched(JobRetryRequested::class);

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Completed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(0)
        ->failed_at->toBeNull()
        ->and($progress->items)->toHaveCount(0)
        ->and(Progress::query()->count())->toBe(1);
});

it('skips completed and cancelled jobs when both middleware are registered', function (string $transition, ProgressStatus $status) {
    $progress = Progress::start();
    $progress->missed(metadata: ['row' => 1]);
    $progress->{$transition}();

    $attributes = $progress->fresh()->getAttributes();

    Queue::connection('sync')->push(new MiddlewareProgressJob($progress));

    expect($progress->fresh()->getAttributes())->toBe($attributes)
        ->and($progress->fresh()->status)->toBe($status)
        ->and($progress->items)->toHaveCount(1);
})->with([
    'completed' => ['complete', ProgressStatus::Completed],
    'cancelled' => ['cancel', ProgressStatus::Cancelled],
]);

class MiddlewareProgressJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Progress $progress
    ) {}

    public function middleware(): array
    {
        return [
            new RestartFailedProgress($this->progress),
            new SkipIfProgressFinished($this->progress),
        ];
    }

    public function handle(): void
    {
        $this->progress->advance();

        if (config('progress-testing.fail', false)) {
            throw new RuntimeException('Unable to finish sync');
        }

        $this->progress->complete();
    }

    public function failed(?Throwable $exception): void
    {
        $this->progress->fail($exception);
    }
}
