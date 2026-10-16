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
    <a href="#testing">Testing</a>
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

Implement `TracksProgress` and use `InteractsWithProgress` alongside Laravel's `Queueable` trait:

```php
use App\Models\Contact;
use DirectoryTree\Progress\Concerns\InteractsWithProgress;
use DirectoryTree\Progress\Contracts\TracksProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncContacts implements ShouldQueue, TracksProgress
{
    use InteractsWithProgress, Queueable;

    public function handle(): void
    {
        foreach (Contact::query()->cursor() as $contact) {
            $contact->syncToCrm();

            $this->progress->advance();
        }
    }
}

SyncContacts::dispatch();
```

The package creates the progress record, starts it before handling, and completes it after successful processing. Terminal job failures are recorded automatically. You only need to call `advance()` as work is processed; no per-item history is stored unless you request it.

See [Jobs](#jobs) for customization, retries, and middleware behavior.

## Manual Tracking

Track work outside the automatic job integration with `Progress::start()`:

```php
use DirectoryTree\Progress\Models\Progress;

$progress = Progress::start(
    name: 'Import contacts',
    total: $rows->count(),
);
```

Advance the processed count as work is completed:

```php
foreach ($rows as $index => $row) {
    try {
        Contact::create($row);

        $progress->advance();
    } catch (Throwable $exception) {
        $progress->missed(
            $exception,
            metadata: ['row' => $index + 1],
        );
    }
}

$progress->complete();
```

## Counters and Ledger

Counters and per-item history are independent:

- Counters measure units of work and drive `percentage()`, `pendingItems()`, and `hasFailures()`.
- Ledger entries describe what happened to a specific resource, including its metadata or exception.

The `total_items` value on a `Progress` record represents units of work, not the number of ledger entries you expect to create. A failed item has still been processed, so failures increment both `processed_items` and `failed_items` by default.

Start with count-only tracking. Add ledger entries when you need to inspect individual resources or failures.

| Mode             | Successful item                           | Failed item                                               |
| ---------------- | ----------------------------------------- | --------------------------------------------------------- |
| Count only       | `$progress->advance()`                     | `$progress->missed()`                                      |
| Count and ledger | `$progress->record($contact)`              | `$progress->missed($exception, item: $contact)`              |
| Ledger only      | `$progress->record($contact, count: false)` | `$progress->missed($exception, item: $contact, count: false)` |

Use count-only tracking for high-volume work where per-item history is unnecessary. Both `advance()` and `incrementFailed()` accept an amount for batches:

```php
$progress->advance($successful);

$progress->incrementFailed($failed);
```

Use ledger-only tracking when a resource should appear in the history but does not represent another unit of progress. For example, one imported row may count once while creating several related models:

```php
$progress->record($contact);

foreach ($contact->tags as $tag) {
    $progress->record($tag, count: false);
}
```

Tracking modes can be mixed. This example counts every attempted row while retaining ledger entries only for failures:

```php
try {
    $importer->process($row);

    $progress->advance();
} catch (Throwable $exception) {
    $progress->missed($exception, metadata: ['row' => $row]);
}
```

Keep these behaviors in mind:

- `incrementFailed()` increments both the processed and failed counters.
- Ledger-only entries do not affect progress or aggregate helpers such as `hasFailures()`.
- `advance()` creates no `ProgressItem`. `record()` without a model is also count-only, as is `missed()` without any details.
- Passing an exception, model, or metadata to `missed()` creates a failed `ProgressItem`.
- Count-only failures cannot retain a per-item exception or metadata because no ledger entry is created.
- Counters and `$progress->items()->count()` may differ intentionally.
- Calls are not idempotent. Repeating a counting call increments the counters again, while repeating a ledger-producing call creates another `ProgressItem`. Queue retries require application-level idempotency.
- Progress does not reject counters that exceed `total_items`. In that case, `percentage()` remains capped at `100` and `pendingItems()` remains at `0`.

## Starting Progress

Pass the model being tracked, the owner, type, and metadata when the progress record needs more context:

```php
$progress = Progress::start(
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
enum ProgressType: string
{
    case Import = 'import';
}

$progress = Progress::start(
    name: 'Import contacts',
    type: ProgressType::Import,
);
```

## Lifecycle

Progress can be completed, failed, or cancelled:

```php
$progress->complete();

$progress->fail($exception);

$progress->cancel();
```

Progress statuses are derived from their lifecycle timestamps, keeping those timestamps as the single source of truth.

Terminal transitions are idempotent and the first terminal state wins. Repeating the same transition returns `true` without changing its timestamp, while attempting a different terminal transition returns `false`. Use `restart()` to explicitly start the progress again.

Restarting keeps the same progress ID, name, type, total, subject, owner, and metadata:

```php
$progress->restart();
```

The reset deletes the associated ledger items, clears the counters, exception, and terminal timestamps, and sets a fresh `started_at` timestamp. These changes happen in a single transaction. Previous execution history is discarded.

Calling `restart()` always resets the progress, even when it is already active. Stop any existing workers for the progress before explicitly restarting it.

Use `restartIfFailed()` to reset only a failed progress record:

```php
$progress->restartIfFailed();
```

Both methods reload the persisted progress under a row lock. `restartIfFailed()` checks that state inside the same transaction as the reset, preserving progress if another worker has already restarted the progress.

## Metrics

Read the percentage, counts, and lifecycle state:

```php
$progress->percentage();

$progress->finished();

$progress->cancelled();

$progress->hasFailures();

$progress->pendingItems();

$progress->processedItems();
```

### Cursor-backed progress

Omit the total when processing a cursor or another source whose size is unknown:

```php
$progress = Progress::start(name: 'Sync contacts');

foreach (Contact::query()->cursor() as $contact) {
    try {
        $contact->syncToCrm();

        $progress->advance();
    } catch (Throwable $exception) {
        $progress->missed($exception, item: $contact);
    }
}

$progress->complete();
```

When the total is unknown, `total_items`, `pendingItems()`, and `percentage()` return `null` while the progress is active. The processed and failed counters continue to update, allowing an interface to display the number processed with an indeterminate progress indicator. Once the progress is completed, `percentage()` returns `100`.

## Ledger Items

Use `record()` when a processed item maps to a persisted Eloquent model:

```php
$progress->record($contact);
```

Attach metadata when the ledger entry needs additional context:

```php
$progress->record($contact, metadata: ['source' => 'import']);
```

Use `missed()` with an item when a persisted model failed:

```php
$progress->missed($exception, item: $contact);
```

Use `missed()` when there is no model to attach:

```php
$progress->missed(
    $exception,
    metadata: ['row' => 42],
);
```

## Relationships

Add `HasProgress` to models that should expose their progress records:

```php
use Illuminate\Database\Eloquent\Model;
use DirectoryTree\Progress\Concerns\HasProgress;

class Import extends Model
{
    use HasProgress;
}
```

Then access progress with familiar Eloquent relationships:

```php
$import->progress;
```

This is a collection: a model can have multiple progress records. Ledger entries belong to their parent through `$item->progress`, using the `progress_id` foreign key. The tables are `progress` and `progress_items`.

Progress keeps ownership available through the `owner` morph and the `owner` argument on `Progress::start()`, but it does not prescribe a relationship name for your application. Define one directly when you need it:

```php
use DirectoryTree\Progress\Models\Progress;
use Illuminate\Database\Eloquent\Relations\MorphMany;

public function operations(): MorphMany
{
    return $this->morphMany(Progress::class, 'owner');
}
```

## Jobs

Progress can manage a job's progress automatically, or you can manage it yourself by passing the progress into the job.

### Automatically managed progress

Implement `TracksProgress` and use `InteractsWithProgress` alongside Laravel's `Queueable` trait:

```php
use App\Models\Contact;
use DirectoryTree\Progress\Concerns\InteractsWithProgress;
use DirectoryTree\Progress\Contracts\TracksProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncContacts implements ShouldQueue, TracksProgress
{
    use InteractsWithProgress, Queueable;

    public function handle(): void
    {
        foreach (Contact::query()->cursor() as $contact) {
            $contact->syncToCrm();

            $this->progress->advance();
        }
    }
}

SyncContacts::dispatch();
```

The package creates a pending progress record before Laravel serializes the job, using the job's class basename as its name. The model is available as `$this->progress` during `middleware()`, `handle()`, and `failed()`.

Laravel's `ShouldBeUnique` behavior is unchanged: a dispatch rejected by its unique lock does not create a progress record.

The interface opts the job into owning the progress record's entire lifecycle:

- The progress starts when execution reaches Progress's middleware, not while the job is waiting in the queue.
- Successful processing completes the progress automatically, after `handle()` and the surrounding middleware have returned.
- Releasing the job leaves the progress active. Automatic attempts preserve its existing progress and ledger.
- A terminal job failure marks the progress failed and stores the exception. You don't need a `failed()` method, but your own callback still runs if you define one.
- Executing a previously failed progress record clears its counters and ledger, then restarts the same record. This works with `queue:retry` and Horizon retries without relying on retry events or attempt numbers.
- Completed and cancelled progress skip execution. Cancellation during `handle()` is not overwritten by automatic completion.

You only need to record progress. There is no need to register `RestartFailedProgress` or `SkipIfProgressFinished`, or call `complete()` and `fail()` yourself.

Override `createProgress()` to customize the persisted progress:

```php
use DirectoryTree\Progress\Models\Progress;

public function createProgress(): Progress
{
    return Progress::create([
        'name' => 'Sync contacts',
        'type' => 'crm',
        'metadata' => ['source' => 'contacts'],
    ]);
}
```

This method runs when the queue payload is created, usually in the process dispatching the job. Return a saved `Progress` or a subclass, and leave `started_at` unset to keep it pending until execution. Its model class, ID, and database connection are included under the `progress` key in queue payload metadata, including for encrypted jobs, so terminal failures can be recorded without deserializing the job again.

Progress appends its middleware without replacing your `middleware()` method or middleware registered with `through()`. Your middleware runs first, so overlap locks and rate limits can prevent execution before the package starts or restarts tracking:

```php
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        (new WithoutOverlapping('progress:'.$this->progress->getKey()))->shared(),
    ];
}
```

If your middleware skips handling entirely, the progress is left unchanged. Cancel it explicitly when permanently abandoning the work.

The model is attached before serialization so the original payload always contains the same progress ID. Replaying that payload retains the record; dispatch a new job instance when you want a separate progress record.

Use this integration for one job that owns one progress record. Child jobs contributing to shared progress should receive the model through their constructor and manage progress explicitly instead. Restarting only resets the package's records, not external side effects; automatic attempts still require checkpoints or idempotent work.

The integration runs through Laravel's queue, including `dispatchSync()`. Calling `handle()` directly or using `Bus::dispatchNow()` bypasses it. Faked dispatches do not create progress records.

Creating a progress record is a database write before the payload is pushed. For transactional dispatch, keep the model on the same database connection as the transaction and use `afterCommit()`. A rolled-back dispatch then rolls back the pending record too. A failed queue push outside a transaction may leave a pending progress record; the package does not remove it automatically.

### Manually managed progress

Pass the progress into queued jobs as a normal constructor dependency:

```php
use DirectoryTree\Progress\Models\Progress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportContact implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Progress $progress,
        public array $row,
    ) {}

    public function handle(): void
    {
        if (! $this->progress->isActive()) {
            return;
        }

        try {
            $contact = Contact::create($this->row);

            $this->progress->record($contact);
        } catch (Throwable $exception) {
            $this->progress->missed($exception, metadata: [
                'row' => $this->row
            ]);
        }
    }
}
```

Dispatch the job with the progress and its input:

```php
ImportContact::dispatch($progress, $row);
```

Laravel serializes the progress as an Eloquent model identifier and restores it when the job is processed.

### Retrying jobs

Add `RestartFailedProgress` to a job that owns the progress lifecycle. It restarts a failed progress record before `handle()` executes. It checks the model's persisted state, so it works for jobs retried through `queue:retry` or Horizon, as well as newly dispatched jobs that receive failed progress.

Pair it with `SkipIfProgressFinished` when completed or cancelled progress should prevent execution:

```php
use App\Models\Contact;
use DirectoryTree\Progress\Middleware\RestartFailedProgress;
use DirectoryTree\Progress\Middleware\SkipIfProgressFinished;
use DirectoryTree\Progress\Models\Progress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncContacts implements ShouldQueue
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
        foreach (Contact::query()->cursor() as $contact) {
            $contact->syncToCrm();

            $this->progress->advance();
        }

        $this->progress->complete();
    }

    public function failed(?Throwable $exception): void
    {
        $this->progress->fail($exception);
    }
}
```

`RestartFailedProgress` preserves counters and history for pending and running progress records and always continues to the next middleware. It does not skip completed or cancelled progress.

`SkipIfProgressFinished` skips failed, completed, and cancelled progress records. Place it after `RestartFailedProgress` so failed records are restarted before checking whether to skip. With both middleware registered, completed and cancelled records skip `handle()` and are treated as processed by the queue. To execute them again, explicitly call `restart()` before dispatching the job.

Include the `failed()` callback alongside the middleware. Laravel calls it when the job permanently fails, marking the progress as failed so the middleware knows to restart it later. Without this callback, an exhausted job may leave its progress marked as running.

Exceptions from `handle()` propagate to Laravel normally. Automatic queue attempts keep using the active progress, and completion remains an explicit call to `complete()`.

Laravel restores the job's original payload when calling `failed()`. Because restarting preserves the progress ID, the restored model still refers to the same progress.

`RestartFailedProgress` checks persisted state under a row lock before restarting. It releases that lock before the job executes. It does not use `$this->attempts()`, which also increases when jobs are released or retried automatically.

If jobs for the same progress may execute concurrently, place Laravel's `WithoutOverlapping` middleware before both Progress middleware:

```php
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        (new WithoutOverlapping('progress:'.$this->progress->getKey()))->shared(),
        new RestartFailedProgress($this->progress),
        new SkipIfProgressFinished($this->progress),
    ];
}
```

Only the job owning the entire operation should use `RestartFailedProgress`. Child jobs that contribute to a shared progress record should not restart it independently.

Restarting discards the previous ledger and progress. It does not reverse external side effects or make the underlying work idempotent. Automatic queue attempts keep existing progress, so jobs that restart work must safely handle inputs that were already processed. Applications that resume partial work should keep checkpoints or other idempotency guarantees.

### Skipping finished progress

Use `SkipIfProgressFinished` on its own when jobs should only execute for pending or running progress:

```php
use DirectoryTree\Progress\Middleware\SkipIfProgressFinished;

public function middleware(): array
{
    return [new SkipIfProgressFinished($this->progress)];
}
```

The middleware reloads the progress from the database before checking `finished()`. It skips failed, completed, and cancelled progress without resetting their state or progress.

## Querying

Progress includes a few common query methods:

```php
Progress::active()->get();

Progress::completed()->latest()->get();

Progress::failed()->latest()->get();

Progress::query()->cancelled()->latest()->get();
```

Progress items can also be scoped:

```php
$progress->items()->completed()->get();

$progress->items()->failed()->get();
```

## Testing

Install the dependencies and run the test suite:

```bash
composer install
composer test
```
