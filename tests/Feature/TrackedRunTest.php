<?php

use DirectoryTree\Progress\Concerns\InteractsWithRun;
use DirectoryTree\Progress\Contracts\TracksRun;
use DirectoryTree\Progress\Enums\RunStatus;
use DirectoryTree\Progress\Middleware\TrackRun;
use DirectoryTree\Progress\Models\Run;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

it('attaches a pending progress before serializing the job', function () {
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config(['queue.connections.database' => [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]]);

    $job = new TrackedRunJob;

    Queue::connection('database')->push($job);

    $run = Run::sole();
    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    $restored = unserialize($payload['data']['command']);

    expect($run)
        ->status->toBe(RunStatus::Pending)
        ->name->toBe('TrackedRunJob')
        ->started_at->toBeNull()
        ->and($job->getRun()->is($run))->toBeTrue()
        ->and($restored->run->is($run))->toBeTrue()
        ->and($restored->middleware)->toBe([TrackRun::class])
        ->and($payload['data']['command'])->toContain('ModelIdentifier')
        ->and($payload['progress'])->toBe([
            'class' => Run::class,
            'id' => $run->getKey(),
            'connection' => 'testing',
        ]);

    Queue::connection('database')->push($job);

    expect($job->middleware)->toBe([TrackRun::class])
        ->and(Run::count())->toBe(1);
});

it('automatically starts and completes progress on the sync queue', function () {
    TrackedRunJob::dispatchSync();

    expect(Run::sole())
        ->status->toBe(RunStatus::Completed)
        ->processed_items->toBe(1)
        ->started_at->not->toBeNull()
        ->completed_at->not->toBeNull()
        ->and(config('progress-testing.status'))->toBe(RunStatus::Running);
});

it('only creates progress for unique jobs that are actually dispatched', function () {
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
        'cache.default' => 'array',
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ]);

    UniqueTrackedRunJob::dispatch();
    UniqueTrackedRunJob::dispatch();

    expect(Run::count())->toBe(1)
        ->and(Run::sole()->status)->toBe(RunStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(1);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect(Run::sole()->status)->toBe(RunStatus::Completed);

    UniqueTrackedRunJob::dispatch();

    expect(Run::count())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('allows jobs to customize their progress and retain their own middleware', function () {
    config(['progress-testing.order' => []]);

    $job = (new CustomizedTrackedRunJob)->through([TrackedRunPropertyMiddleware::class]);

    Queue::connection('sync')->push($job);

    expect(Run::sole())
        ->name->toBe('Sync contacts')
        ->type->toBe('crm')
        ->total_items->toBe(10)
        ->metadata->toBe(['source' => 'contacts.csv'])
        ->status->toBe(RunStatus::Completed)
        ->and(config('progress-testing.order'))->toBe(['method', 'property', 'handle'])
        ->and($job->middleware)->toBe([TrackedRunPropertyMiddleware::class, TrackRun::class]);
});

it('preserves job failure callbacks and marks progress failed automatically', function (bool $callbackFails) {
    config([
        'progress-testing.action' => 'throw',
        'progress-testing.fail-callback' => $callbackFails,
    ]);

    expect(fn () => TrackedRunWithFailureCallbackJob::dispatchSync())
        ->toThrow(RuntimeException::class, $callbackFails ? 'Failure callback failed' : 'Unable to finish sync');

    $run = Run::sole();

    expect($run)
        ->status->toBe(RunStatus::Failed)
        ->processed_items->toBe(1)
        ->completed_at->toBeNull()
        ->exception->toContain('Unable to finish sync')
        ->and(config('progress-testing.failed-progress'))->toBe($run->getKey());
})->with([false, true]);

it('does not complete progress when its job explicitly fails', function () {
    config(['progress-testing.action' => 'fail']);

    TrackedRunJob::dispatchSync();

    expect(Run::sole())
        ->status->toBe(RunStatus::Failed)
        ->processed_items->toBe(1)
        ->completed_at->toBeNull()
        ->exception->toContain('Manually failed sync');
});

it('does not override cancellation during handling', function () {
    config(['progress-testing.action' => 'cancel']);

    TrackedRunJob::dispatchSync();

    expect(Run::sole())
        ->status->toBe(RunStatus::Cancelled)
        ->completed_at->toBeNull();
});

it('skips completed and cancelled progress without changing their state', function (string $transition) {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $run->{$transition}();
    $attributes = $run->fresh()->getAttributes();

    Queue::connection('sync')->push((new TrackedRunJob)->setRun($run));

    expect($run->fresh()->getAttributes())->toBe($attributes)
        ->and($run->items)->toHaveCount(1)
        ->and(config('progress-testing.status'))->toBeNull()
        ->and(Run::count())->toBe(1);
})->with(['complete', 'cancel']);

it('leaves the progress pending when earlier middleware releases the job', function () {
    Queue::connection('sync')->push(
        (new TrackedRunJob)->through([ReleaseTrackedRunMiddleware::class])
    );

    expect(Run::sole())
        ->status->toBe(RunStatus::Pending)
        ->started_at->toBeNull()
        ->processed_items->toBe(0)
        ->and(config('progress-testing.status'))->toBeNull();
});

it('marks the progress failed when earlier middleware throws', function () {
    expect(fn () => Queue::connection('sync')->push(
        (new TrackedRunJob)->through([FailTrackedRunMiddleware::class])
    ))->toThrow(RuntimeException::class, 'Middleware failed');

    expect(Run::sole())
        ->status->toBe(RunStatus::Failed)
        ->started_at->toBeNull()
        ->processed_items->toBe(0)
        ->exception->toContain('Middleware failed');
});

it('waits for outer middleware before completing progress', function (string $action, RunStatus $status) {
    config(['progress-testing.after-handle' => $action]);

    $job = (new TrackedRunJob)->through([AfterTrackedRunMiddleware::class]);

    if ($action === 'throw') {
        expect(fn () => Queue::connection('sync')->push($job))
            ->toThrow(RuntimeException::class, 'Middleware failed after handling');
    } else {
        Queue::connection('sync')->push($job);
    }

    expect(Run::sole())
        ->status->toBe($status)
        ->processed_items->toBe(1)
        ->and(is_null(Run::sole()->completed_at))->toBe($status !== RunStatus::Completed);
})->with([
    'failed' => ['throw', RunStatus::Failed],
    'released' => ['release', RunStatus::Running],
    'completed' => ['continue', RunStatus::Completed],
]);

it('does not complete active progress when earlier middleware skips handling', function () {
    $run = Run::start();
    $run->advance();

    Queue::connection('sync')->push(
        (new TrackedRunJob)->setRun($run)->through([SkipTrackedRunMiddleware::class])
    );

    expect($run->refresh())
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(1)
        ->completed_at->toBeNull()
        ->and(config('progress-testing.status'))->toBeNull();
});

it('preserves counters when a job is released or retried automatically', function (string $action) {
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
        'progress-testing.action' => $action,
    ]);

    TrackedRunJob::dispatch();

    $run = Run::sole();

    artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 2])
        ->assertExitCode(0);

    expect($run->refresh())
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(1)
        ->failed_at->toBeNull()
        ->completed_at->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);

    $startedAt = $run->started_at;

    config(['progress-testing.action' => null]);

    artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 2])
        ->assertExitCode(0);

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->processed_items->toBe(2)
        ->started_at->toEqual($startedAt)
        ->and(Run::count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['release', 'throw']);

it('restarts the same progress after terminal failure and queue retry', function (string $jobClass) {
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
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
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
        'progress-testing.action' => 'throw',
    ]);

    $jobClass::dispatch();

    $run = Run::sole();

    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 2])
        ->assertExitCode(0);

    expect($run->refresh())
        ->status->toBe(RunStatus::Failed)
        ->processed_items->toBe(2)
        ->exception->toContain('Unable to finish sync')
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    $run->missed(metadata: ['row' => 1]);

    config(['progress-testing.action' => null]);

    artisan('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]])
        ->assertExitCode(0);

    expect($run->fresh()->status)->toBe(RunStatus::Failed);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(0)
        ->failed_at->toBeNull()
        ->exception->toBeNull()
        ->and($run->items)->toHaveCount(0)
        ->and(Run::count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with([TrackedRunJob::class, EncryptedTrackedRunJob::class]);

it('restarts progress when original payloads are replayed with a new queue uuid', function (string $jobClass) {
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
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ]);

    $jobClass::dispatch();

    $run = Run::sole();
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
        ->and(Run::count())->toBe(1);
})->with([TrackedRunJob::class, EncryptedTrackedRunJob::class]);

it('rolls back pending progress creation with an after-commit database dispatch', function () {
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config(['queue.connections.database' => [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]]);

    DB::beginTransaction();

    Queue::connection('database')->push((new TrackedRunJob)->afterCommit());

    expect(Run::sole()->status)->toBe(RunStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(0);

    DB::rollBack();

    expect(Run::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('dispatches and processes the pending progress after a transaction commits', function () {
    config([
        'database.connections.progress-transactions' => config('database.connections.testing'),
        'database.default' => 'progress-transactions',
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'progress-transactions',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ]);

    artisan('migrate', [
        '--database' => 'progress-transactions',
        '--path' => __DIR__.'/../../database/migrations',
        '--realpath' => true,
        '--force' => true,
    ])->assertExitCode(0);

    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    DB::beginTransaction();

    Queue::connection('database')->push((new TrackedRunJob)->afterCommit());

    $run = Run::sole();

    expect($run->status)->toBe(RunStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(0);

    DB::commit();

    expect($run->fresh()->status)->toBe(RunStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(1);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->processed_items->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('creates independent managed progress for chained jobs', function () {
    config(['queue.default' => 'sync']);

    Bus::chain([new TrackedRunJob, new TrackedRunJob])->dispatch();

    expect(Run::count())->toBe(2)
        ->and(Run::completed()->count())->toBe(2)
        ->and(Run::pluck('processed_items')->all())->toBe([1, 1]);
});

it('does not create progress for faked dispatches', function () {
    Queue::fake();

    TrackedRunJob::dispatch();

    Queue::assertPushed(TrackedRunJob::class);

    Bus::fake();

    TrackedRunJob::dispatch();

    Bus::assertDispatched(TrackedRunJob::class);

    expect(Run::count())->toBe(0);
});

it('tracks custom progress models on their configured connection', function (?string $action, RunStatus $status) {
    config([
        'database.connections.progress-testing' => config('database.connections.testing'),
        'progress-testing.action' => $action,
    ]);

    artisan('migrate', [
        '--database' => 'progress-testing',
        '--path' => __DIR__.'/../../database/migrations',
        '--realpath' => true,
        '--force' => true,
    ])->assertExitCode(0);

    if ($action === 'throw') {
        expect(fn () => CustomModelTrackedRunJob::dispatchSync())
            ->toThrow(RuntimeException::class, 'Unable to finish sync');
    } else {
        CustomModelTrackedRunJob::dispatchSync();
    }

    expect(CustomTrackedRun::on('progress-testing')->sole())
        ->status->toBe($status)
        ->processed_items->toBe(1)
        ->and(Run::count())->toBe(0);
})->with([
    'completed' => [null, RunStatus::Completed],
    'failed' => ['throw', RunStatus::Failed],
]);

it('fails pending progress when queue attempts are exhausted before handling', function () {
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
        'queue.failed.driver' => null,
    ]);

    TrackedRunJob::dispatch();

    DB::table('jobs')->update(['attempts' => 1]);

    artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1])
        ->assertExitCode(0);

    expect(Run::sole())
        ->status->toBe(RunStatus::Failed)
        ->started_at->toBeNull()
        ->processed_items->toBe(0)
        ->exception->toContain('MaxAttemptsExceededException')
        ->and(config('progress-testing.status'))->toBeNull();
});

it('does not replace missing progress exceptions with failure listener errors', function () {
    $run = Run::create();
    $run->delete();

    expect(fn () => Queue::connection('sync')->push((new TrackedRunJob)->setRun($run)))
        ->toThrow(ModelNotFoundException::class);

    expect(Run::count())->toBe(0)
        ->and(config('progress-testing.status'))->toBeNull();
});

it('does not manage progress without the opt-in interface', function () {
    $run = Run::start();

    Queue::connection('sync')->push((new ManuallyTrackedRunJob)->setRun($run));

    expect($run->refresh())
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(1);

    config(['progress-testing.action' => 'throw']);

    expect(fn () => Queue::connection('sync')->push((new ManuallyTrackedRunJob)->setRun($run)))
        ->toThrow(RuntimeException::class, 'Unmanaged failure');

    expect($run->refresh())
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(2)
        ->failed_at->toBeNull()
        ->and(Run::count())->toBe(1);
});

class TrackedRunJob implements ShouldQueue, TracksRun
{
    use InteractsWithRun, Queueable;

    public function handle(): void
    {
        config(['progress-testing.status' => $this->run->status]);

        $this->run->advance();

        match (config('progress-testing.action')) {
            'throw' => throw new RuntimeException('Unable to finish sync'),
            'release' => $this->release(),
            'fail' => $this->fail(new RuntimeException('Manually failed sync')),
            'cancel' => $this->run->cancel(),
            default => null,
        };
    }
}

class EncryptedTrackedRunJob extends TrackedRunJob implements ShouldBeEncrypted {}

class UniqueTrackedRunJob extends TrackedRunJob implements ShouldBeUnique {}

class TrackedRunWithFailureCallbackJob extends TrackedRunJob
{
    public function failed(?Throwable $exception): void
    {
        config(['progress-testing.failed-progress' => $this->run->getKey()]);

        if (config('progress-testing.fail-callback')) {
            throw new RuntimeException('Failure callback failed');
        }
    }
}

class CustomTrackedRun extends Run
{
    protected $table = 'runs';
}

class CustomModelTrackedRunJob extends TrackedRunJob
{
    public function createRun(): Run
    {
        return CustomTrackedRun::on('progress-testing')->create(['name' => 'Sync contacts']);
    }
}

class CustomizedTrackedRunJob extends TrackedRunJob
{
    public function createRun(): Run
    {
        return Run::create([
            'name' => 'Sync contacts',
            'type' => 'crm',
            'total_items' => 10,
            'metadata' => ['source' => 'contacts.csv'],
        ]);
    }

    public function middleware(): array
    {
        return [TrackedRunMethodMiddleware::class];
    }

    public function handle(): void
    {
        config(['progress-testing.order' => [...config('progress-testing.order'), 'handle']]);

        parent::handle();
    }
}

class TrackedRunMethodMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        config(['progress-testing.order' => [...config('progress-testing.order'), 'method']]);

        return $next($job);
    }
}

class TrackedRunPropertyMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        config(['progress-testing.order' => [...config('progress-testing.order'), 'property']]);

        return $next($job);
    }
}

class ReleaseTrackedRunMiddleware
{
    public function handle(object $job, Closure $next): void
    {
        $job->release();
    }
}

class FailTrackedRunMiddleware
{
    public function handle(object $job, Closure $next): void
    {
        throw new RuntimeException('Middleware failed');
    }
}

class AfterTrackedRunMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        $result = $next($job);

        expect($job->run->fresh()->status)->toBe(RunStatus::Running);

        if (config('progress-testing.after-handle') === 'throw') {
            throw new RuntimeException('Middleware failed after handling');
        }

        if (config('progress-testing.after-handle') === 'release') {
            $job->release();
        }

        return $result;
    }
}

class SkipTrackedRunMiddleware
{
    public function handle(object $job, Closure $next): void {}
}

class ManuallyTrackedRunJob implements ShouldQueue
{
    use InteractsWithRun, Queueable;

    public function handle(): void
    {
        $this->run->advance();

        if (config('progress-testing.action') === 'throw') {
            throw new RuntimeException('Unmanaged failure');
        }
    }
}
