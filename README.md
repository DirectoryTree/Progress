# Stride

Stride is an Eloquent-native ledger for tracking long-running work in Laravel applications.

Use it for imports, exports, syncs, backfills, cleanup jobs, scheduled tasks, webhook replays, queued workflows, and any other process where you need to know what ran, how far it got, what failed, and which models were touched.

## Installation

Stride requires PHP 8.2 or later and supports Laravel >= 11.

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

Terminal transitions are idempotent and the first terminal state wins. Repeating the same transition returns `true` without changing its timestamp, while attempting a different terminal transition returns `false`. Start a new run when retrying failed or cancelled work.

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
