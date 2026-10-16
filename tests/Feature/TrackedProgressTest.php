<?php

use DirectoryTree\Progress\Concerns\InteractsWithProgress;
use DirectoryTree\Progress\Contracts\TracksProgress;
use DirectoryTree\Progress\Enums\ProgressStatus;
use DirectoryTree\Progress\Middleware\TrackProgress;
use DirectoryTree\Progress\Models\Progress;
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

    $job = new TrackedProgressJob;

    Queue::connection('database')->push($job);

    $progress = Progress::sole();
    $payload = json_decode(DB::table('jobs')->value('payload'), true);
    $restored = unserialize($payload['data']['command']);

    expect($progress)
        ->status->toBe(ProgressStatus::Pending)
        ->name->toBe('TrackedProgressJob')
        ->started_at->toBeNull()
        ->and($job->getProgress()->is($progress))->toBeTrue()
        ->and($restored->progress->is($progress))->toBeTrue()
        ->and($restored->middleware)->toBe([TrackProgress::class])
        ->and($payload['data']['command'])->toContain('ModelIdentifier')
        ->and($payload['progress'])->toBe([
            'class' => Progress::class,
            'id' => $progress->getKey(),
            'connection' => 'testing',
        ]);

    Queue::connection('database')->push($job);

    expect($job->middleware)->toBe([TrackProgress::class])
        ->and(Progress::count())->toBe(1);
});

it('automatically starts and completes progress on the sync queue', function () {
    TrackedProgressJob::dispatchSync();

    expect(Progress::sole())
        ->status->toBe(ProgressStatus::Completed)
        ->processed_items->toBe(1)
        ->started_at->not->toBeNull()
        ->completed_at->not->toBeNull()
        ->and(config('progress-testing.status'))->toBe(ProgressStatus::Running);
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

    UniqueTrackedProgressJob::dispatch();
    UniqueTrackedProgressJob::dispatch();

    expect(Progress::count())->toBe(1)
        ->and(Progress::sole()->status)->toBe(ProgressStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(1);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect(Progress::sole()->status)->toBe(ProgressStatus::Completed);

    UniqueTrackedProgressJob::dispatch();

    expect(Progress::count())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('allows jobs to customize their progress and retain their own middleware', function () {
    config(['progress-testing.order' => []]);

    $job = (new CustomizedTrackedProgressJob)->through([TrackedProgressPropertyMiddleware::class]);

    Queue::connection('sync')->push($job);

    expect(Progress::sole())
        ->name->toBe('Sync contacts')
        ->type->toBe('crm')
        ->total_items->toBe(10)
        ->metadata->toBe(['source' => 'contacts.csv'])
        ->status->toBe(ProgressStatus::Completed)
        ->and(config('progress-testing.order'))->toBe(['method', 'property', 'handle'])
        ->and($job->middleware)->toBe([TrackedProgressPropertyMiddleware::class, TrackProgress::class]);
});

it('preserves job failure callbacks and marks progress failed automatically', function (bool $callbackFails) {
    config([
        'progress-testing.action' => 'throw',
        'progress-testing.fail-callback' => $callbackFails,
    ]);

    expect(fn () => TrackedProgressWithFailureCallbackJob::dispatchSync())
        ->toThrow(RuntimeException::class, $callbackFails ? 'Failure callback failed' : 'Unable to finish sync');

    $progress = Progress::sole();

    expect($progress)
        ->status->toBe(ProgressStatus::Failed)
        ->processed_items->toBe(1)
        ->completed_at->toBeNull()
        ->exception->toContain('Unable to finish sync')
        ->and(config('progress-testing.failed-progress'))->toBe($progress->getKey());
})->with([false, true]);

it('does not complete progress when its job explicitly fails', function () {
    config(['progress-testing.action' => 'fail']);

    TrackedProgressJob::dispatchSync();

    expect(Progress::sole())
        ->status->toBe(ProgressStatus::Failed)
        ->processed_items->toBe(1)
        ->completed_at->toBeNull()
        ->exception->toContain('Manually failed sync');
});

it('does not override cancellation during handling', function () {
    config(['progress-testing.action' => 'cancel']);

    TrackedProgressJob::dispatchSync();

    expect(Progress::sole())
        ->status->toBe(ProgressStatus::Cancelled)
        ->completed_at->toBeNull();
});

it('skips completed and cancelled progress without changing their state', function (string $transition) {
    $progress = Progress::start();
    $progress->missed(metadata: ['row' => 1]);
    $progress->{$transition}();
    $attributes = $progress->fresh()->getAttributes();

    Queue::connection('sync')->push((new TrackedProgressJob)->setProgress($progress));

    expect($progress->fresh()->getAttributes())->toBe($attributes)
        ->and($progress->items)->toHaveCount(1)
        ->and(config('progress-testing.status'))->toBeNull()
        ->and(Progress::count())->toBe(1);
})->with(['complete', 'cancel']);

it('leaves the progress pending when earlier middleware releases the job', function () {
    Queue::connection('sync')->push(
        (new TrackedProgressJob)->through([ReleaseTrackedProgressMiddleware::class])
    );

    expect(Progress::sole())
        ->status->toBe(ProgressStatus::Pending)
        ->started_at->toBeNull()
        ->processed_items->toBe(0)
        ->and(config('progress-testing.status'))->toBeNull();
});

it('marks the progress failed when earlier middleware throws', function () {
    expect(fn () => Queue::connection('sync')->push(
        (new TrackedProgressJob)->through([FailTrackedProgressMiddleware::class])
    ))->toThrow(RuntimeException::class, 'Middleware failed');

    expect(Progress::sole())
        ->status->toBe(ProgressStatus::Failed)
        ->started_at->toBeNull()
        ->processed_items->toBe(0)
        ->exception->toContain('Middleware failed');
});

it('waits for outer middleware before completing progress', function (string $action, ProgressStatus $status) {
    config(['progress-testing.after-handle' => $action]);

    $job = (new TrackedProgressJob)->through([AfterTrackedProgressMiddleware::class]);

    if ($action === 'throw') {
        expect(fn () => Queue::connection('sync')->push($job))
            ->toThrow(RuntimeException::class, 'Middleware failed after handling');
    } else {
        Queue::connection('sync')->push($job);
    }

    expect(Progress::sole())
        ->status->toBe($status)
        ->processed_items->toBe(1)
        ->and(is_null(Progress::sole()->completed_at))->toBe($status !== ProgressStatus::Completed);
})->with([
    'failed' => ['throw', ProgressStatus::Failed],
    'released' => ['release', ProgressStatus::Running],
    'completed' => ['continue', ProgressStatus::Completed],
]);

it('does not complete active progress when earlier middleware skips handling', function () {
    $progress = Progress::start();
    $progress->advance();

    Queue::connection('sync')->push(
        (new TrackedProgressJob)->setProgress($progress)->through([SkipTrackedProgressMiddleware::class])
    );

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Running)
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

    TrackedProgressJob::dispatch();

    $progress = Progress::sole();

    artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 2])
        ->assertExitCode(0);

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Running)
        ->processed_items->toBe(1)
        ->failed_at->toBeNull()
        ->completed_at->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);

    $startedAt = $progress->started_at;

    config(['progress-testing.action' => null]);

    artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 2])
        ->assertExitCode(0);

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Completed)
        ->processed_items->toBe(2)
        ->started_at->toEqual($startedAt)
        ->and(Progress::count())->toBe(1)
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

    $progress = Progress::sole();

    artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 2])
        ->assertExitCode(0);

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Failed)
        ->processed_items->toBe(2)
        ->exception->toContain('Unable to finish sync')
        ->and(DB::table('failed_jobs')->count())->toBe(1);

    $progress->missed(metadata: ['row' => 1]);

    config(['progress-testing.action' => null]);

    artisan('queue:retry', ['id' => [DB::table('failed_jobs')->value('uuid')]])
        ->assertExitCode(0);

    expect($progress->fresh()->status)->toBe(ProgressStatus::Failed);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Completed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(0)
        ->failed_at->toBeNull()
        ->exception->toBeNull()
        ->and($progress->items)->toHaveCount(0)
        ->and(Progress::count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with([TrackedProgressJob::class, EncryptedTrackedProgressJob::class]);

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

    $progress = Progress::sole();
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
        ->and(Progress::count())->toBe(1);
})->with([TrackedProgressJob::class, EncryptedTrackedProgressJob::class]);

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

    Queue::connection('database')->push((new TrackedProgressJob)->afterCommit());

    expect(Progress::sole()->status)->toBe(ProgressStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(0);

    DB::rollBack();

    expect(Progress::count())->toBe(0)
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

    Queue::connection('database')->push((new TrackedProgressJob)->afterCommit());

    $progress = Progress::sole();

    expect($progress->status)->toBe(ProgressStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(0);

    DB::commit();

    expect($progress->fresh()->status)->toBe(ProgressStatus::Pending)
        ->and(DB::table('jobs')->count())->toBe(1);

    artisan('queue:work', ['--once' => true, '--sleep' => 0])
        ->assertExitCode(0);

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Completed)
        ->processed_items->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('creates independent managed progress for chained jobs', function () {
    config(['queue.default' => 'sync']);

    Bus::chain([new TrackedProgressJob, new TrackedProgressJob])->dispatch();

    expect(Progress::count())->toBe(2)
        ->and(Progress::completed()->count())->toBe(2)
        ->and(Progress::pluck('processed_items')->all())->toBe([1, 1]);
});

it('does not create progress for faked dispatches', function () {
    Queue::fake();

    TrackedProgressJob::dispatch();

    Queue::assertPushed(TrackedProgressJob::class);

    Bus::fake();

    TrackedProgressJob::dispatch();

    Bus::assertDispatched(TrackedProgressJob::class);

    expect(Progress::count())->toBe(0);
});

it('tracks custom progress models on their configured connection', function (?string $action, ProgressStatus $status) {
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
        expect(fn () => CustomModelTrackedProgressJob::dispatchSync())
            ->toThrow(RuntimeException::class, 'Unable to finish sync');
    } else {
        CustomModelTrackedProgressJob::dispatchSync();
    }

    expect(CustomTrackedProgress::on('progress-testing')->sole())
        ->status->toBe($status)
        ->processed_items->toBe(1)
        ->and(Progress::count())->toBe(0);
})->with([
    'completed' => [null, ProgressStatus::Completed],
    'failed' => ['throw', ProgressStatus::Failed],
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

    TrackedProgressJob::dispatch();

    DB::table('jobs')->update(['attempts' => 1]);

    artisan('queue:work', ['--once' => true, '--sleep' => 0, '--tries' => 1])
        ->assertExitCode(0);

    expect(Progress::sole())
        ->status->toBe(ProgressStatus::Failed)
        ->started_at->toBeNull()
        ->processed_items->toBe(0)
        ->exception->toContain('MaxAttemptsExceededException')
        ->and(config('progress-testing.status'))->toBeNull();
});

it('does not replace missing progress exceptions with failure listener errors', function () {
    $progress = Progress::create();
    $progress->delete();

    expect(fn () => Queue::connection('sync')->push((new TrackedProgressJob)->setProgress($progress)))
        ->toThrow(ModelNotFoundException::class);

    expect(Progress::count())->toBe(0)
        ->and(config('progress-testing.status'))->toBeNull();
});

it('does not manage progress without the opt-in interface', function () {
    $progress = Progress::start();

    Queue::connection('sync')->push((new ManuallyTrackedProgressJob)->setProgress($progress));

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Running)
        ->processed_items->toBe(1);

    config(['progress-testing.action' => 'throw']);

    expect(fn () => Queue::connection('sync')->push((new ManuallyTrackedProgressJob)->setProgress($progress)))
        ->toThrow(RuntimeException::class, 'Unmanaged failure');

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Running)
        ->processed_items->toBe(2)
        ->failed_at->toBeNull()
        ->and(Progress::count())->toBe(1);
});

class TrackedProgressJob implements ShouldQueue, TracksProgress
{
    use InteractsWithProgress, Queueable;

    public function handle(): void
    {
        config(['progress-testing.status' => $this->progress->status]);

        $this->progress->advance();

        match (config('progress-testing.action')) {
            'throw' => throw new RuntimeException('Unable to finish sync'),
            'release' => $this->release(),
            'fail' => $this->fail(new RuntimeException('Manually failed sync')),
            'cancel' => $this->progress->cancel(),
            default => null,
        };
    }
}

class EncryptedTrackedProgressJob extends TrackedProgressJob implements ShouldBeEncrypted {}

class UniqueTrackedProgressJob extends TrackedProgressJob implements ShouldBeUnique {}

class TrackedProgressWithFailureCallbackJob extends TrackedProgressJob
{
    public function failed(?Throwable $exception): void
    {
        config(['progress-testing.failed-progress' => $this->progress->getKey()]);

        if (config('progress-testing.fail-callback')) {
            throw new RuntimeException('Failure callback failed');
        }
    }
}

class CustomTrackedProgress extends Progress
{
    protected $table = 'progress';
}

class CustomModelTrackedProgressJob extends TrackedProgressJob
{
    public function createProgress(): Progress
    {
        return CustomTrackedProgress::on('progress-testing')->create(['name' => 'Sync contacts']);
    }
}

class CustomizedTrackedProgressJob extends TrackedProgressJob
{
    public function createProgress(): Progress
    {
        return Progress::create([
            'name' => 'Sync contacts',
            'type' => 'crm',
            'total_items' => 10,
            'metadata' => ['source' => 'contacts.csv'],
        ]);
    }

    public function middleware(): array
    {
        return [TrackedProgressMethodMiddleware::class];
    }

    public function handle(): void
    {
        config(['progress-testing.order' => [...config('progress-testing.order'), 'handle']]);

        parent::handle();
    }
}

class TrackedProgressMethodMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        config(['progress-testing.order' => [...config('progress-testing.order'), 'method']]);

        return $next($job);
    }
}

class TrackedProgressPropertyMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        config(['progress-testing.order' => [...config('progress-testing.order'), 'property']]);

        return $next($job);
    }
}

class ReleaseTrackedProgressMiddleware
{
    public function handle(object $job, Closure $next): void
    {
        $job->release();
    }
}

class FailTrackedProgressMiddleware
{
    public function handle(object $job, Closure $next): void
    {
        throw new RuntimeException('Middleware failed');
    }
}

class AfterTrackedProgressMiddleware
{
    public function handle(object $job, Closure $next): mixed
    {
        $result = $next($job);

        expect($job->progress->fresh()->status)->toBe(ProgressStatus::Running);

        if (config('progress-testing.after-handle') === 'throw') {
            throw new RuntimeException('Middleware failed after handling');
        }

        if (config('progress-testing.after-handle') === 'release') {
            $job->release();
        }

        return $result;
    }
}

class SkipTrackedProgressMiddleware
{
    public function handle(object $job, Closure $next): void {}
}

class ManuallyTrackedProgressJob implements ShouldQueue
{
    use InteractsWithProgress, Queueable;

    public function handle(): void
    {
        $this->progress->advance();

        if (config('progress-testing.action') === 'throw') {
            throw new RuntimeException('Unmanaged failure');
        }
    }
}
