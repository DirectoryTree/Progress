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

Calling `record()` creates a completed `RunItem` for the model and increments the processed item count. Calling `missed()` creates a failed `RunItem`, optionally associated with a model.

The aggregate counters and item ledger are intentionally separate. You may count progress without storing a ledger entry, or store a ledger entry without counting it toward progress:

```php
$run->record($contact); // Ledger entry and processed count.

$run->record($contact, count: false); // Ledger entry only.

$run->record(); // Processed count only.
```

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

## Items

Use `record()` when a processed item maps to a persisted Eloquent model:

```php
$run->record($contact);
```

Attach metadata when the ledger entry needs additional context:

```php
$run->record($contact, metadata: ['source' => 'import']);
```

Use `record()` without a model when you only want aggregate progress:

```php
$run->record();
```

Pass `count: false` when you only want the ledger entry:

```php
$run->record($contact, count: false);
```

Aggregate helpers such as `hasFailures()` reflect the parent run counters. Ledger-only entries created with `count: false` do not affect those helpers.

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

Every call to `record()` or `missed()` creates a distinct ledger entry. Applications that require one entry per resource should enforce idempotency before recording it.

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
