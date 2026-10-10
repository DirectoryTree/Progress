<h1 align="center">Stride</h1>

<p align="center">Track lifecycle, progress, and resources for long-running work in Laravel applications.</p>

<p align="center">
    <a href="https://github.com/DirectoryTree/Stride/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/DirectoryTree/Stride/run-tests.yml?branch=master&amp;style=flat-square" alt="Tests"></a>
    <a href="https://packagist.org/packages/directorytree/stride"><img src="https://img.shields.io/packagist/dt/directorytree/stride.svg?style=flat-square" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/directorytree/stride"><img src="https://img.shields.io/packagist/v/directorytree/stride.svg?style=flat-square" alt="Latest Version"></a>
    <a href="https://github.com/DirectoryTree/Stride/blob/master/LICENSE.md"><img src="https://img.shields.io/github/license/DirectoryTree/Stride?style=flat-square" alt="License"></a>
</p>

<p align="center">
    <a href="#installation">Installation</a>
    <span> · </span>
    <a href="#usage">Usage</a>
    <span> · </span>
    <a href="#progress">Progress</a>
    <span> · </span>
    <a href="#testing">Testing</a>
</p>

---

Stride is an Eloquent-native ledger for tracking long-running work in Laravel applications.

Use it for imports, exports, syncs, backfills, cleanup jobs, scheduled tasks, webhook replays, queued workflows, and any other process where you need to know what ran, how far it got, what failed, and which models were touched.

## Requirements

- PHP 8.2 or higher
- Laravel 11, 12, or 13

## Installation

Install Stride with Composer:

```bash
composer require directorytree/stride
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=stride-migrations

php artisan migrate
```

## Usage

Start a run with a name and total number of items:

```php
use DirectoryTree\Stride\Models\Run;

$run = Run::start(
    name: 'Import contacts',
    total: $rows->count(),
);
```

Record processed models as the run moves forward:

```php
foreach ($rows as $index => $row) {
    try {
        $contact = Contact::create($row);

        $run->record($contact);
    } catch (Throwable $exception) {
        $run->missed(
            $exception,
            metadata: ['row' => $index + 1],
        );
    }
}

$run->complete();
```

## Counters and Ledger

Stride tracks progress and history independently:

- Counters measure units of work and drive `progress()`, `pendingItems()`, and `hasFailures()`.
- Ledger entries describe what happened to a specific resource, including its metadata or exception.

The `total_items` value on a `Run` should represent the number of progress units in the run, not the number of ledger entries you expect to create. A failed item has still been processed, so failures increment both `processed_items` and `failed_items` by default.

Use both counters and ledger entries when one unit of work maps to one resource. This is the recommended default.

| Mode             | Successful item                        | Failed item                                              |
| ---------------- | -------------------------------------- | -------------------------------------------------------- |
| Count only       | `$run->record()`                       | `$run->missed()`                                         |
| Count and ledger | `$run->record($contact)`               | `$run->missed($exception, item: $contact)`               |
| Ledger only      | `$run->record($contact, count: false)` | `$run->missed($exception, item: $contact, count: false)` |

Use count-only tracking for high-volume work where per-item history is unnecessary. The increment methods also accept an amount when progress is recorded in batches:

```php
$run->incrementProcessed($successful);

$run->incrementFailed($failed);
```

Use ledger-only tracking when a resource should appear in the history but does not represent another unit of progress. For example, one imported row may count once while creating several related models:

```php
$run->record($contact);

foreach ($contact->tags as $tag) {
    $run->record($tag, count: false);
}
```

Tracking modes can be mixed. This example counts every attempted row while retaining ledger entries only for failures:

```php
try {
    $importer->process($row);

    $run->record();
} catch (Throwable $exception) {
    $run->missed($exception, metadata: ['row' => $row]);
}
```

Keep these behaviors in mind:

- `incrementFailed()` increments both the processed and failed counters.
- Ledger-only entries do not affect progress or aggregate helpers such as `hasFailures()`.
- `record()` without a model and `missed()` without any details create no `RunItem`.
- Passing an exception, model, or metadata to `missed()` creates a failed `RunItem`.
- Count-only failures cannot retain a per-item exception or metadata because no ledger entry is created.
- Counters and `$run->items()->count()` may differ intentionally.
- Calls are not idempotent. Repeating a counting call increments the counters again, while repeating a ledger-producing call creates another `RunItem`. Queue retries require application-level idempotency.
- Stride does not reject counters that exceed `total_items`. In that case, `progress()` remains capped at `100` and `pendingItems()` remains at `0`.

## Starting Runs

Pass the model being tracked, the owner, type, and metadata when your run needs more context:

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

## Lifecycle

Runs can be completed, failed, or cancelled:

```php
$run->complete();

$run->fail($exception);

$run->cancel();
```

Run statuses are derived from their lifecycle timestamps, keeping those timestamps as the single source of truth.

Terminal transitions are idempotent and the first terminal state wins. Repeating the same transition returns `true` without changing its timestamp, while attempting a different terminal transition returns `false`. Use `restart()` to explicitly start the run again.

Restarting keeps the same run ID, name, type, total, subject, owner, and metadata:

```php
$run->restart();
```

The reset deletes the run's ledger items, clears its counters, exception, and terminal timestamps, and sets a fresh `started_at` timestamp. These changes happen in a single transaction. Previous execution history is discarded.

Calling `restart()` always resets the run, even when it is already active. Stop any existing workers for the run before explicitly restarting it.

Use `restartIfFailed()` to reset only a failed run:

```php
$run->restartIfFailed();
```

Both methods reload the persisted run under a row lock. `restartIfFailed()` checks that state inside the same transaction as the reset, preserving progress if another worker has already restarted the run.

## Progress

Stride exposes batch-like progress helpers:

```php
$run->progress();

$run->finished();

$run->cancelled();

$run->hasFailures();

$run->pendingItems();

$run->processedItems();
```

### Cursor-backed runs

Omit the total when processing a cursor or another source whose size is unknown:

```php
$run = Run::start(name: 'Sync contacts');

foreach (Contact::query()->cursor() as $contact) {
    try {
        $contact->syncToCrm();

        $run->record($contact);
    } catch (Throwable $exception) {
        $run->missed($exception, item: $contact);
    }
}

$run->complete();
```

When the total is unknown, `total_items`, `pendingItems()`, and `progress()` return `null` while the run is active. The processed and failed counters continue to update, allowing an interface to display the number processed with an indeterminate progress indicator. Once the run is completed, `progress()` returns `100`.

## Ledger Items

Use `record()` when a processed item maps to a persisted Eloquent model:

```php
$run->record($contact);
```

Attach metadata when the ledger entry needs additional context:

```php
$run->record($contact, metadata: ['source' => 'import']);
```

Use `missed()` with an item when a persisted model failed:

```php
$run->missed($exception, item: $contact);
```

Use `missed()` when there is no model to attach:

```php
$run->missed(
    $exception,
    metadata: ['row' => 42],
);
```

## Relationships

Add `HasRuns` to models that should expose run relationships:

```php
use Illuminate\Database\Eloquent\Model;
use DirectoryTree\Stride\Concerns\HasRuns;

class Import extends Model
{
    use HasRuns;
}
```

Then access runs with familiar Eloquent relationships:

```php
$import->runs;
```

Stride keeps ownership available through the `owner` morph and the `owner` argument on `Run::start()`, but it does not prescribe a relationship name for your application. Define one directly when you need it:

```php
use DirectoryTree\Stride\Models\Run;
use Illuminate\Database\Eloquent\Relations\MorphMany;

public function operations(): MorphMany
{
    return $this->morphMany(Run::class, 'owner');
}
```

## Jobs

Pass the run into queued jobs as a normal constructor dependency:

```php
use DirectoryTree\Stride\Models\Run;
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
        if (! $this->run->isActive()) {
            return;
        }

        try {
            $contact = Contact::create($this->row);

            $this->run->record($contact);
        } catch (Throwable $exception) {
            $this->run->missed($exception, metadata: [
                'row' => $this->row
            ]);
        }
    }
}
```

Dispatch the job with the run and its input:

```php
ImportContact::dispatch($run, $row);
```

Laravel serializes the run as an Eloquent model identifier and restores it when the job is processed.

### Retrying jobs

Add `RestartRunOnRetry` to a job that owns the run lifecycle. The middleware restarts failed runs before `handle()` executes, whether the job was retried through `queue:retry` or Horizon:

```php
use App\Models\Contact;
use DirectoryTree\Stride\Middleware\RestartRunOnRetry;
use DirectoryTree\Stride\Models\Run;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncContacts implements ShouldQueue
{
    use Queueable;

    public function __construct(public Run $run) {}

    public function middleware(): array
    {
        return [new RestartRunOnRetry($this->run)];
    }

    public function handle(): void
    {
        foreach (Contact::query()->cursor() as $contact) {
            $contact->syncToCrm();

            $this->run->record($contact);
        }

        $this->run->complete();
    }

    public function failed(?Throwable $exception): void
    {
        $this->run->fail($exception);
    }
}
```

The middleware keeps existing progress for pending and running runs. Completed and cancelled runs skip `handle()` and are treated as processed by the queue. To execute them again, explicitly call `restart()` before dispatching the job.

Include the `failed()` callback alongside the middleware. Laravel calls it when the job permanently fails, marking the run as failed so the middleware knows to restart it later. Without this callback, an exhausted job may leave its run marked as running.

Exceptions from `handle()` propagate to Laravel normally. Automatic queue attempts keep using the active run, and completion remains an explicit call to `complete()`.

Laravel restores the job's original payload when calling `failed()`. Because restarting preserves the run ID, the restored model still refers to the same run.

The middleware checks persisted state under a row lock before restarting. It releases that lock before the job executes. It does not use `$this->attempts()`, which also increases when jobs are released or retried automatically.

If jobs for the same run may execute concurrently, place Laravel's `WithoutOverlapping` middleware before `RestartRunOnRetry`:

```php
use Illuminate\Queue\Middleware\WithoutOverlapping;

public function middleware(): array
{
    return [
        (new WithoutOverlapping('run:'.$this->run->getKey()))->shared(),
        new RestartRunOnRetry($this->run),
    ];
}
```

Only the job owning the entire run should use `RestartRunOnRetry`. Child jobs that contribute progress to a shared run should not restart it independently.

Restarting discards the previous ledger and progress. It does not reverse external side effects or make the underlying work idempotent. Automatic queue attempts keep existing progress, so jobs that restart work must safely handle inputs that were already processed. Applications that resume partial work should keep checkpoints or other idempotency guarantees.

## Querying

Stride includes a few common query methods:

```php
Run::active()->get();

Run::completed()->latest()->get();

Run::failed()->latest()->get();

Run::query()->cancelled()->latest()->get();
```

Run items can also be scoped:

```php
$run->items()->completed()->get();

$run->items()->failed()->get();
```

## Testing

Install the dependencies and run the test suite:

```bash
composer install
composer test
```
