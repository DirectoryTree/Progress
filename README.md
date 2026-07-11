# Stride

Stride is an Eloquent-native ledger for tracking long-running work in Laravel applications.

Use it for imports, exports, syncs, backfills, cleanup jobs, scheduled tasks, webhook replays, queued workflows, and any other process where you need to know what ran, how far it got, what failed, and which models were touched.

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
    runnable: $integration,
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

## Progress

Stride exposes batch-like progress helpers:

```php
$run->processedItems();

$run->pendingItems();

$run->progress();

$run->finished();

$run->cancelled();

$run->hasFailures();
```

## Items

Use `record()` when a processed item maps to a persisted Eloquent model:

```php
$run->record($contact);
```

Use `record()` without a model when you only want aggregate progress:

```php
$run->record();
```

Pass `count: false` when you only want the ledger entry:

```php
$run->record($contact, count: false);
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

Queued jobs may use `InteractsWithRun` to attach and retrieve their run, similar to how Laravel jobs interact with batches:

```php
use DirectoryTree\Stride\Concerns\InteractsWithRun;

class ImportContact
{
    use InteractsWithRun;

    public function handle(): void
    {
        if (! $this->runActive()) {
            return;
        }

        // ...
    }
}
```

Attach a run before dispatching the job:

```php
dispatch((new ImportContact($row))->withRun($run));
```

Inside the job, retrieve the run:

```php
$this->run()?->record($contact);
```

## Querying

Stride includes a few common query methods:

```php
Run::active()->get();

Run::completed()->latest()->get();

Run::failed()->latest()->get();
```

Run items can also be scoped:

```php
$run->items()->completed()->get();

$run->items()->failed()->get();
```
