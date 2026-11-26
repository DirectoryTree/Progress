<h1 align="center">Progress</h1>

<p align="center">Track the progress of long-running work in Laravel applications.</p>

<p align="center">
    <a href="https://github.com/DirectoryTree/Progress/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/DirectoryTree/Progress/run-tests.yml?branch=master&amp;style=flat-square" alt="Tests"></a>
    <a href="https://packagist.org/packages/directorytree/progress"><img src="https://img.shields.io/packagist/dt/directorytree/progress.svg?style=flat-square" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/directorytree/progress"><img src="https://img.shields.io/packagist/v/directorytree/progress.svg?style=flat-square" alt="Latest Version"></a>
    <a href="https://github.com/DirectoryTree/Progress/blob/master/LICENSE.md"><img src="https://img.shields.io/github/license/DirectoryTree/Progress?style=flat-square" alt="License"></a>
</p>

<p align="center">
    <a href="#installation">Installation</a>
    <span> · </span>
    <a href="#usage">Usage</a>
    <span> · </span>
    <a href="#metrics">Metrics</a>
    <span> · </span>
    <a href="#jobs">Jobs</a>
</p>

---

Progress tracks how far your work has gotten, with automatic job lifecycle management and optional per-resource history.

Use it for imports, exports, syncs, backfills, cleanup jobs, scheduled tasks, webhook replays, queued workflows, and any other process where you need to know what ran, how far it got, what failed, and which models were touched.

## Requirements

- PHP 8.2 or higher
- Laravel 11, 12, or 13

## Installation

Install Progress with Composer:

```bash
composer require directorytree/progress
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=progress-migrations

php artisan migrate
```

## Usage

Implement `TracksRun` and use `InteractsWithRun` alongside Laravel's `Queueable` trait:

```php
use App\Models\Contact;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use DirectoryTree\Progress\Contracts\TracksRun;
use DirectoryTree\Progress\Concerns\InteractsWithRun;

class SyncContacts implements ShouldQueue, TracksRun
{
    use InteractsWithRun, Queueable;

    public function handle(): void
    {
        foreach (Contact::query()->cursor() as $contact) {
            $contact->syncToCrm();

            $this->run->advance();
        }
    }
}

SyncContacts::dispatch();
```

The package creates the run, starts it before handling, and completes it after successful processing. Terminal job failures are recorded automatically. You only need to call `advance()` as work is processed; no per-item history is stored unless you request it.

See [Jobs](#jobs) for customization, retries, and middleware behavior.

## Manual Tracking

Track work outside the automatic job integration with `Run::start()`:

```php
use DirectoryTree\Progress\Models\Run;

$run = Run::start(
    name: 'Import contacts',
    total: $rows->count(),
);
```

Advance the processed count as work is completed:

```php
foreach ($rows as $index => $row) {
    try {
        Contact::create($row);
    } catch (Throwable $exception) {
        $run->missed(
            $exception,
            metadata: ['row' => $index + 1],
        );

        continue;
    }

    $run->advance();
}

$run->complete();
```

### Customizing a run

Pass the model being tracked, the owner, type, and metadata when the run needs more context:

```php
$run = Run::start(
    name: 'Sync products',
    total: $products->count(),
    subject: $integration,
    owner: $user,
    type: 'sync',
    metadata: ['source' => 'stripe'],
);
```

The `name` and `type` arguments also accept backed enums:

```php
enum RunType: string
{
    case Import = 'import';
}

$run = Run::start(
    name: 'Import contacts',
    type: RunType::Import,
);
```

## Counters and Ledger

Counters measure units of work. Ledger entries describe individual outcomes. Start with counters alone and add entries when you need to inspect a resource, its metadata, or an exception.

| Mode             | Successful item                        | Failed item                                              |
| ---------------- | -------------------------------------- | -------------------------------------------------------- |
| Count only       | `$run->advance()`                      | `$run->missed()`                                         |
| Count and ledger | `$run->record($contact)`               | `$run->missed($exception, item: $contact)`                |
| Ledger only      | `$run->record($contact, count: false)` | `$run->missed($exception, item: $contact, count: false)` |

A failure is still a processed item. With no details, `missed()` is shorthand for `advance(failed: 1)`: it increments both counters without creating a ledger entry. `record()` without details is likewise count-only.

### Counting a batch

The amount passed to `advance()` is the total processed, **including** failures. The optional `failed` count must be between zero and that amount:

```php
$run->advance(10, failed: 2); // 10 processed: 8 succeeded and 2 failed.
```

`advance(0)` returns `0` without writing. Non-empty batches update the counters atomically without firing `Run` model events or saving unrelated edits. The run instance reflects its own increments; use `$run->fresh()` to read totals that include other workers' progress.

### Ledger items

Pass a persisted model to track the resource, or pass metadata alone when there is no model to attach:

```php
$run->record($contact, metadata: ['source' => 'import']);

$run->record(metadata: ['row' => 42]);

$run->missed($exception, metadata: ['row' => 42]);
```

Each entry stores its outcome in `status` and its recording time in `created_at`. Eloquent casts `$item->status` to `DirectoryTree\Progress\Enums\RunItemStatus` (`Completed` or `Failed`). Unlike a run, a ledger entry has no lifecycle timestamps or `updated_at`.

Use `count: false` when a resource belongs in the history but does not represent another unit of work. For example, one imported row may create a contact and several tags:

```php
$run->record($contact);

foreach ($contact->tags as $tag) {
    $run->record($tag, count: false);
}
```

- **Totals count work, not entries.** Ledger-only entries do not affect `percentage()`, `pendingItems()`, or `hasFailures()`. Totals and increments may be zero but not negative. Omit the total if it is unknown. If the processed count exceeds the total, the percentage is capped at 100 and pending items at zero.
- **Counted entries are atomic.** On a saved run, Progress updates the counters and then inserts the entry in the same transaction. If either write fails, neither is kept. The ledger item fires its normal Eloquent events.
- **Retries can double-count.** Repeating a counting call increments the counters again; repeating a ledger-producing call creates another entry. Make retried work idempotent when counts must be exact.

## Lifecycle

`Run::start()` creates a run that is already running. To create the run before work begins, use `Run::create()` and call `begin()` on that same record when processing starts:

```php
$run = Run::create(['name' => 'Import contacts']);

// When the worker is ready to process the import:
if (! $run->begin()) {
    return;
}
```

`begin()` sets `started_at` once. It returns `true` for pending or already running runs without resetting counters or ledger entries, and `false` for completed, failed, or cancelled runs. Jobs using `TracksRun` call it automatically.

Runs can be completed, failed, or cancelled:

```php
$run->complete();

$run->fail($exception);

$run->cancel();
```

Run statuses are derived from their lifecycle timestamps, keeping those timestamps as the single source of truth.

Terminal transitions are idempotent and the first terminal state wins. Repeating the same transition returns `true` without changing its timestamp, while attempting a different terminal transition returns `false`. Use `restart()` to explicitly start the run again.

If an Eloquent observer vetoes a lifecycle save by returning `false`, Progress throws a `RuntimeException`. A vetoed update leaves the run in its previous persisted state.

Unlike counter increments, lifecycle transitions use normal Eloquent model saves.

Restarting keeps the same run ID, name, type, total, subject, owner, and metadata:

```php
$run->restart();
```

The reset deletes the associated ledger items, clears the counters, exception, and terminal timestamps, and sets a fresh `started_at` timestamp. These changes happen in a single transaction. Previous execution history is discarded.

Calling `restart()` always resets the run, even when it is already active. Stop any existing workers for the run before explicitly restarting it.

Use `restartIfFailed()` to reset only a failed run:

```php
$run->restartIfFailed();
```

Both methods reload the run under a row lock. `restartIfFailed()` checks that state inside the same transaction as the reset, preserving progress if another worker has already restarted the run.

## Metrics

Read the percentage, counts, and lifecycle state:

```php
$run->percentage();

$run->isFinished();

$run->isCancelled();

$run->isFailed();

$run->hasFailures();

$run->pendingItems();

$run->processedItems();
```

`isFailed()` describes the run itself. `hasFailures()` checks its failed-item counter, so a completed run can still have failures.

### Cursor-backed runs

Omit the total when processing a cursor or another source whose size is unknown:

```php
$run = Run::start(name: 'Sync contacts');

foreach (Contact::query()->cursor() as $contact) {
    try {
        $contact->syncToCrm();
    } catch (Throwable $exception) {
        $run->missed($exception, item: $contact);

        continue;
    }

    $run->advance();
}

$run->complete();
```

When the total is unknown, `total_items`, `pendingItems()`, and `percentage()` return `null` while the run is active. The processed and failed counters continue to update, allowing an interface to display the number processed with an indeterminate progress indicator. Once the run is completed, `percentage()` returns `100`.

## Relationships

Add `HasRuns` to models that should expose their runs:

```php
use Illuminate\Database\Eloquent\Model;
use DirectoryTree\Progress\Concerns\HasRuns;

class Import extends Model
{
    use HasRuns;
}
```

Then access runs with familiar Eloquent relationships:

```php
$import->runs;
```

This is a collection: a model can have multiple runs. Ledger entries belong to their parent through `$item->run`, using the `run_id` foreign key. The tables are `runs` and `run_items`.

Progress keeps ownership available through the `owner` morph and the `owner` argument on `Run::start()`, but it does not prescribe a relationship name for your application. Define one directly when you need it:

```php
use DirectoryTree\Progress\Models\Run;
use Illuminate\Database\Eloquent\Relations\MorphMany;

public function operations(): MorphMany
{
    return $this->morphMany(Run::class, 'owner');
}
```

## Querying

Progress includes a few common query methods:

```php
Run::active()->get();

Run::completed()->latest()->get();

Run::failed()->latest()->get();

Run::cancelled()->latest()->get();
```

Run items can also be scoped:

```php
$run->items()->completed()->get();

$run->items()->failed()->get();
```

## Jobs

Progress can manage a job's progress automatically, or you can manage it yourself by passing the run into the job.

### Automatically managed runs

When you dispatch a `TracksRun` job like the one in [Usage](#usage), Progress creates a pending run before Laravel serializes the job. Its default name is the job's class basename. The model is available as `$this->run` during `middleware()`, `handle()`, and `failed()`.

Laravel's `ShouldBeUnique` behavior is unchanged: a dispatch rejected by its unique lock does not create a run.

The interface opts the job into owning the run's entire lifecycle:

- The run starts when execution reaches the tracking middleware.
- Successful processing completes the run automatically, after `handle()` and the surrounding middleware have returned.
- Releasing the job leaves the run active. Automatic attempts preserve its existing progress and ledger.
- A terminal job failure marks the run failed and stores the exception. You don't need a `failed()` method, but your own callback still runs if you define one.
- If Laravel discards the job because a serialized model is missing and `deleteWhenMissingModels` is enabled, the run keeps its existing status. A run that has never started stays pending.
- Executing a previously failed run clears its counters and ledger, then restarts the same record. This works with `queue:retry` and Horizon retries without relying on retry events or attempt numbers.
- Completed and cancelled runs skip execution. Cancellation during `handle()` is not overwritten by automatic completion.

You only need to record progress. There is no need to register `RestartFailedRun` or `SkipIfRunFinished`, or call `complete()` and `fail()` yourself.

Override `createRun()` to customize the run:

```php
use DirectoryTree\Progress\Models\Run;

public function createRun(): Run
{
    return Run::create([
        'name' => 'Sync contacts',
        'type' => 'crm',
        'metadata' => ['source' => 'contacts'],
    ]);
}
```

This method runs when the queue payload is created, usually in the process dispatching the job. Return a saved `Run` or a subclass, and leave `started_at` unset to keep it pending until execution. Its model class, ID, and database connection are included under the `progress_run` key in queue payload metadata, including for encrypted jobs, so terminal failures can be recorded without deserializing the job again.

Progress appends its middleware without replacing your `middleware()` method or middleware registered with `through()`. Your middleware runs first, so overlap locks and rate limits can prevent execution before the package starts or restarts tracking:

```php
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        (new WithoutOverlapping('progress:'.$this->run->getKey()))->shared(),
    ];
}
```

If your middleware skips handling entirely, the run is left unchanged. Cancel it explicitly when permanently abandoning the work.

The model is attached before serialization so the original payload always contains the same run ID. Replaying that payload retains the record; dispatch a new job instance when you want a separate run.

Use this integration for one job that owns one run. Child jobs contributing to shared progress should receive the model through their constructor and manage progress explicitly instead. Restarting only resets the package's records, not external side effects; automatic attempts still require checkpoints or idempotent work.

The integration runs through Laravel's queue, including `dispatchSync()`. Calling `handle()` directly or using `Bus::dispatchNow()` bypasses it. Faked dispatches do not create runs.

Progress records completion after Laravel has finished processing the job. An exception during that write propagates to Laravel, and the run may still show as running even though the work finished. Once you've verified that the work succeeded, you can call `complete()` on the run to reconcile its status.

Creating a run is a database write before the payload is pushed. For transactional dispatch, keep the model on the same database connection as the transaction and use `afterCommit()`. A rolled-back dispatch then rolls back the pending record too. A failed queue push outside a transaction may leave a pending run; the package does not remove it automatically.

### Manually managed runs

Pass the run into queued jobs as a normal constructor dependency:

```php
use DirectoryTree\Progress\Models\Run;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportContact implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Run $run,
        public array $row,
    ) {}

    public function handle(): void
    {
        if (! $this->run->begin()) {
            return;
        }

        try {
            $contact = Contact::create($this->row);
        } catch (Throwable $exception) {
            $this->run->missed($exception, metadata: [
                'row' => $this->row,
            ]);

            return;
        }

        $this->run->record($contact);
    }
}
```

Dispatch the job with the run and its input:

```php
$run = Run::create(['name' => 'Import contact']);

ImportContact::dispatch($run, $row);
```

Laravel serializes the run as an Eloquent model identifier and restores it when the job is processed.

### Sharing a run across a batch

When several jobs contribute to one run, let a [Laravel job batch](https://laravel.com/docs/queues#job-batching) own completion and failure. Pass the same `Run` to each job; don't implement `TracksRun` on them, since finishing one job doesn't mean the whole import is finished.

For example, an inbox import may discover messages one page at a time. In an authenticated request, create a pending run without a total. Use the saved `App\Models\Mailbox` as the subject and the user requesting the import as the owner:

```php
use App\Jobs\ImportInbox;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use DirectoryTree\Progress\Models\Run;

$run = new Run(['name' => 'Import inbox']);

$run->subject()->associate($mailbox);
$run->owner()->associate(auth()->user());

$run->save();

$runId = $run->getKey();

Bus::batch([new ImportInbox($mailbox, $run)])
    ->then(static function (Batch $batch) use ($runId): void {
        if (! $batch->cancelled()) {
            Run::findOrFail($runId)->complete();
        }
    })
    ->catch(static function (Batch $batch, Throwable $exception) use ($runId): void {
        Run::findOrFail($runId)->fail($exception);
    })
    ->dispatch();
```

The page job adds the message jobs **and the next page** to the current batch before returning. A standalone `dispatch()` would put that work outside the batch, allowing the run to complete too early. Here, `imap()` is an application method on the mailbox model that returns its ImapEngine client:

```php
namespace App\Jobs;

use App\Models\Mailbox;
use Illuminate\Bus\Batchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use DirectoryTree\Progress\Models\Run;
use DirectoryTree\ImapEngine\Pagination\Cursor;

class ImportInbox implements ShouldQueue, ShouldBeEncrypted
{
    use Batchable, Queueable;

    public function __construct(
        public Mailbox $mailbox,
        public Run $run,
        public ?Cursor $cursor = null,
    ) {}

    public function handle(): void
    {
        $batch = $this->batch();

        if ($batch->cancelled() || ! $this->run->begin()) {
            return;
        }

        $page = $this->mailbox->imap()
            ->inbox()
            ->messages()
            ->cursorPaginate(perPage: 100, cursor: $this->cursor);

        $jobs = [];

        foreach ($page->items() as $message) {
            $jobs[] = new ImportMessage($this->mailbox, $message->uid(), $this->run);
        }

        if ($cursor = $page->nextCursor()) {
            $jobs[] = new self($this->mailbox, $this->run, $cursor);
        }

        if ($jobs) {
            $batch->add($jobs);
        }
    }
}
```

Only the message job advances the run, after the application's importer succeeds. Its middleware skips finished runs; the check in `handle()` also skips the import if the batch itself was cancelled:

```php
namespace App\Jobs;

use App\Models\Mailbox;
use Illuminate\Bus\Batchable;
use App\Services\MessageImporter;
use DirectoryTree\Progress\Models\Run;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use DirectoryTree\Progress\Middleware\SkipIfRunFinished;

class ImportMessage implements ShouldQueue, ShouldBeEncrypted
{
    use Batchable, Queueable;

    public function __construct(
        public Mailbox $mailbox,
        public int $uid,
        public Run $run,
    ) {}

    public function middleware(): array
    {
        return [new SkipIfRunFinished($this->run)];
    }

    public function handle(MessageImporter $importer): void
    {
        if ($this->batch()->cancelled()) {
            return;
        }

        $importer->import($this->mailbox->imap(), $this->uid);

        $this->run->advance();
    }
}
```

The run counts messages, not page jobs. An empty inbox completes with zero processed items. Let exceptions propagate for Laravel to retry: `catch()` fails the run on the first terminal job failure, while `then()` completes it only after all pages and messages succeed. If you explicitly cancel the batch, cancel the run as well.

Retries can replay a page or a message. When counts must be exact, persist a checkpoint per run and message, and advance only when recording that checkpoint for the first time, in the same database transaction.

### Retrying jobs

Add `RestartFailedRun` to a job that owns the run lifecycle. It restarts a failed run before `handle()` executes. It checks the model's persisted state, so it works for jobs retried through `queue:retry` or Horizon, as well as newly dispatched jobs that receive a failed run.

Pair it with `SkipIfRunFinished` when completed or cancelled runs should prevent execution:

```php
use Throwable;
use App\Models\Contact;
use DirectoryTree\Progress\Models\Run;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use DirectoryTree\Progress\Middleware\RestartFailedRun;
use DirectoryTree\Progress\Middleware\SkipIfRunFinished;

class SyncContacts implements ShouldQueue
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
        foreach (Contact::query()->cursor() as $contact) {
            $contact->syncToCrm();

            $this->run->advance();
        }

        $this->run->complete();
    }

    public function failed(?Throwable $exception): void
    {
        $this->run->fail($exception);
    }
}
```

`RestartFailedRun` preserves counters and history for pending and running runs and always continues to the next middleware. It does not skip completed or cancelled runs.

`SkipIfRunFinished` skips failed, completed, and cancelled runs. Place it after `RestartFailedRun` so failed records are restarted before checking whether to skip. With both middleware registered, completed and cancelled records skip `handle()` and are treated as processed by the queue. To execute them again, explicitly call `restart()` before dispatching the job.

Include the `failed()` callback alongside the middleware. Laravel calls it when the job permanently fails, marking the run as failed so the middleware knows to restart it later. Without this callback, an exhausted job may leave its run marked as running.

Exceptions from `handle()` propagate to Laravel normally. Automatic queue attempts keep using the active run, and completion remains an explicit call to `complete()`.

Laravel restores the job's original payload when calling `failed()`. Because restarting preserves the run ID, the restored model still refers to the same run.

`RestartFailedRun` checks persisted state under a row lock before restarting. It releases that lock before the job executes. It does not use `$this->attempts()`, which also increases when jobs are released or retried automatically.

If jobs for the same run may execute concurrently, place Laravel's `WithoutOverlapping` middleware before both Progress middleware:

```php
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        (new WithoutOverlapping('progress:'.$this->run->getKey()))->shared(),
        new RestartFailedRun($this->run),
        new SkipIfRunFinished($this->run),
    ];
}
```

Only the job owning the entire operation should use `RestartFailedRun`. Child jobs that contribute to a shared run should not restart it independently.

Restarting discards the previous ledger and progress. It does not reverse external side effects or make the underlying work idempotent. Automatic queue attempts keep existing progress, so jobs that restart work must safely handle inputs that were already processed. Applications that resume partial work should keep checkpoints or other idempotency guarantees.

### Skipping finished runs

Use `SkipIfRunFinished` on its own when jobs should only execute for pending or running runs:

```php
use DirectoryTree\Progress\Middleware\SkipIfRunFinished;

public function middleware(): array
{
    return [new SkipIfRunFinished($this->run)];
}
```

The middleware reloads the run from the database before checking `isFinished()`. It skips failed, completed, and cancelled runs without resetting their state or progress.

### Testing jobs that use Progress

#### Dispatching a job

Use `Queue::fake()` when testing that an endpoint dispatches the job. The job won't run or automatically create a `Run`.

```php
use App\Jobs\SyncContacts;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\post;

it('queues a contact sync', function () {
    Queue::fake();

    post('/contacts/sync');

    Queue::assertPushed(SyncContacts::class);
});
```

For a batch, use `Bus::fake()` and `Bus::assertBatched()` to check what was dispatched, not whether its completion callback ran.

#### Testing the automatic lifecycle

Use `dispatchSync()` to exercise the same job's queue middleware and completion event. Leave the queue and bus real; fake the CRM request instead. This example assumes an isolated test database with the Progress migrations applied and a `syncToCrm()` method that uses Laravel's HTTP client.

```php
use App\Models\Contact;
use App\Jobs\SyncContacts;
use Illuminate\Support\Facades\Http;
use DirectoryTree\Progress\Models\Run;
use DirectoryTree\Progress\Enums\RunStatus;

it('completes the run after syncing a contact', function () {
    Http::fake();
    Contact::factory()->create();

    SyncContacts::dispatchSync();

    expect(Run::sole())
        ->status->toBe(RunStatus::Completed)
        ->processed_items->toBe(1)
        ->started_at->not->toBeNull();
});
```

#### Testing a batch child

Give `ImportMessage` a real run, a fake batch, and a mock importer. Calling `handle()` directly tests the child's work without running its middleware or the batch callbacks.

```php
use App\Models\Mailbox;
use App\Jobs\ImportMessage;
use App\Services\MessageImporter;
use DirectoryTree\Progress\Models\Run;
use DirectoryTree\Progress\Enums\RunStatus;

it('counts an imported message without completing the shared run', function () {
    $run = Run::start();

    $mailbox = Mailbox::factory()->create();

    $importer = Mockery::mock(MessageImporter::class);
    $importer->shouldReceive('import')->once();

    [$job] = (new ImportMessage($mailbox, uid: 42, run: $run))->withFakeBatch();

    $job->handle($importer);

    expect($run->fresh())
        ->processed_items->toBe(1)
        ->status->toBe(RunStatus::Running);
});
```
