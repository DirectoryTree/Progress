<?php

use DirectoryTree\Stride\Concerns\HasRuns;
use DirectoryTree\Stride\Enums\RunStatus;
use DirectoryTree\Stride\Models\Builders\RunBuilder;
use DirectoryTree\Stride\Models\Builders\RunItemBuilder;
use DirectoryTree\Stride\Models\Run;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('articles', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
});

it('starts runs', function () {
    $run = Run::start(
        name: 'Import contacts',
        total: 10,
    );

    expect($run)
        ->status->toBe(RunStatus::Running)
        ->started_at->not->toBeNull()
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->processedItems()->toBe(0)
        ->hasFailures()->toBeFalse();

    expect($run->progress())->toBe(0);
});

it('starts runs with backed enum names and types', function () {
    $run = Run::start(
        name: RunName::ImportContacts,
        type: RunType::Import,
    );

    expect($run)
        ->name->toBe('Import contacts')
        ->type->toBe('import');
});

it('derives statuses from lifecycle timestamps', function () {
    $run = Run::query()->create();

    expect(Schema::hasColumn('runs', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('run_items', 'status'))->toBeFalse()
        ->and($run->status)->toBe(RunStatus::Pending)
        ->and($run->processedItems())->toBe(0)
        ->and($run->hasFailures())->toBeFalse();

    $run->forceFill(['started_at' => now()])->save();

    expect($run->status)->toBe(RunStatus::Running);
});

it('classifies active run statuses', function () {
    expect(RunStatus::Pending->active())->toBeTrue()
        ->and(RunStatus::Running->active())->toBeTrue()
        ->and(RunStatus::Completed->active())->toBeFalse()
        ->and(RunStatus::Failed->active())->toBeFalse()
        ->and(RunStatus::Cancelled->active())->toBeFalse();
});

it('tracks progress', function () {
    $run = Run::start(total: 10);

    $run->incrementProcessed(5);

    expect($run->refresh()->progress())->toBe(50);
});

it('tracks indeterminate progress without a total', function () {
    $run = Run::start();

    $run->incrementProcessed(5);

    expect($run->refresh())
        ->total_items->toBeNull()
        ->processedItems()->toBe(5)
        ->pendingItems()->toBeNull()
        ->progress()->toBeNull();
});

it('distinguishes an unknown total from a known empty run', function () {
    $run = Run::start(total: 0);
    $failedRun = Run::start(total: 0);
    $cancelledRun = Run::start(total: 0);

    $failedRun->fail();
    $cancelledRun->cancel();

    expect($run)
        ->total_items->toBe(0)
        ->pendingItems()->toBe(0)
        ->progress()->toBe(0)
        ->and($failedRun->progress())->toBe(0)
        ->and($cancelledRun->progress())->toBe(0);
});

it('completes runs', function () {
    $run = Run::start();

    $run->complete();

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->completed_at->not->toBeNull();

    expect($run->progress())->toBe(100);
});

it('records missed models', function () {
    $run = Run::start(total: 1);

    $article = Article::query()->create();

    $resource = $run->missed(
        new RuntimeException('Unable to process article'),
        item: $article,
    );

    expect($resource)
        ->status->toBe(RunStatus::Failed)
        ->resource->is($article)->toBeTrue();

    expect($run)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->hasFailures()->toBeTrue()
        ->and($run->fresh()->processed_items)->toBe(1)
        ->and($run->fresh()->failed_items)->toBe(1);
});

it('starts runs with relationships and metadata', function () {
    $owner = Article::query()->create();
    $subject = Article::query()->create();

    $run = Run::start(
        name: 'Import contacts',
        total: 10,
        subject: $subject,
        owner: $owner,
        type: 'import',
        metadata: ['disk' => 'local'],
    );

    expect($run)
        ->name->toBe('Import contacts')
        ->type->toBe('import')
        ->status->toBe(RunStatus::Running)
        ->total_items->toBe(10)
        ->metadata->toBe(['disk' => 'local'])
        ->and($run->owner->is($owner))->toBeTrue()
        ->and($run->subject->is($subject))->toBeTrue();
});

it('restarts runs without changing their identity or configuration', function () {
    $owner = Article::query()->create();
    $subject = Article::query()->create();

    $run = Run::start(
        name: 'Sync contacts',
        type: 'sync',
        total: 2,
        subject: $subject,
        owner: $owner,
        metadata: ['source' => 'crm'],
    );

    $run->record($subject);
    $run->missed(new RuntimeException('Unable to sync contact'));
    $run->fail(new RuntimeException('Unable to finish sync'));
    $run->load('items');

    $id = $run->getKey();
    $createdAt = $run->created_at;
    $startedAt = now()->subMinute();

    $run->forceFill(['started_at' => $startedAt])->save();

    $restarted = $run->restart();

    expect($restarted)->toBe($run)
        ->and($run->getKey())->toBe($id)
        ->and($run->relationLoaded('items'))->toBeFalse()
        ->and($run)
        ->name->toBe('Sync contacts')
        ->type->toBe('sync')
        ->total_items->toBe(2)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->metadata->toBe(['source' => 'crm'])
        ->exception->toBeNull()
        ->status->toBe(RunStatus::Running)
        ->completed_at->toBeNull()
        ->failed_at->toBeNull()
        ->cancelled_at->toBeNull()
        ->and($run->created_at)->toEqual($createdAt)
        ->and($run->started_at->greaterThan($startedAt))->toBeTrue()
        ->and($run->owner->is($owner))->toBeTrue()
        ->and($run->subject->is($subject))->toBeTrue()
        ->and($run->items)->toHaveCount(0)
        ->and($run->fresh()->processed_items)->toBe(0)
        ->and($run->fresh()->failed_items)->toBe(0)
        ->and($run->fresh()->status)->toBe(RunStatus::Running)
        ->and(Run::query()->count())->toBe(1)
        ->and(DB::table('run_items')->count())->toBe(0)
        ->and(Schema::hasColumn('runs', 'original_run_id'))->toBeFalse()
        ->and(Schema::hasColumn('runs', 'attempt'))->toBeFalse();
});

it('restarts failed runs conditionally', function () {
    $run = Run::start(name: 'Sync contacts', total: 2, metadata: ['source' => 'crm']);
    $run->missed(new RuntimeException('Unable to sync contact'), metadata: ['row' => 1]);
    $run->fail(new RuntimeException('Unable to finish sync'));
    $run->load('items');

    $id = $run->getKey();
    $startedAt = now()->subMinute();
    $run->forceFill(['started_at' => $startedAt])->save();

    expect($run->restartIfFailed())->toBe($run)
        ->and($run->getKey())->toBe($id)
        ->and($run->relationLoaded('items'))->toBeFalse()
        ->and($run)
        ->name->toBe('Sync contacts')
        ->total_items->toBe(2)
        ->metadata->toBe(['source' => 'crm'])
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->exception->toBeNull()
        ->failed_at->toBeNull()
        ->and($run->started_at->greaterThan($startedAt))->toBeTrue()
        ->and($run->items)->toHaveCount(0)
        ->and($run->fresh()->status)->toBe(RunStatus::Running)
        ->and(Run::query()->count())->toBe(1);
});

it('preserves non-failed runs when conditionally restarting', function (RunStatus $status) {
    $run = Run::query()->create([
        'started_at' => $status === RunStatus::Pending ? null : now(),
        'completed_at' => $status === RunStatus::Completed ? now() : null,
        'cancelled_at' => $status === RunStatus::Cancelled ? now() : null,
    ]);
    $run->missed(metadata: ['row' => 1]);

    $attributes = $run->fresh()->getAttributes();

    expect($run->restartIfFailed())->toBe($run)
        ->and($run->getAttributes())->toBe($attributes)
        ->and($run->fresh()->getAttributes())->toBe($attributes)
        ->and($run->status)->toBe($status)
        ->and($run->items)->toHaveCount(1);
})->with([RunStatus::Pending, RunStatus::Running, RunStatus::Completed, RunStatus::Cancelled]);

it('conditionally restarts using persisted failures instead of stale state', function () {
    $run = Run::start();
    $staleRun = $run->fresh();

    $run->missed(metadata: ['row' => 1]);
    $run->fail();

    $staleRun->restartIfFailed();

    expect($staleRun)
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->and($staleRun->items)->toHaveCount(0)
        ->and($run->fresh()->status)->toBe(RunStatus::Running);
});

it('preserves restarted progress when conditionally restarting stale failed models', function () {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $run->fail();

    $staleRun = $run->fresh()->load('items');

    $run->restartIfFailed();
    $run->record();

    $attributes = $run->fresh()->getAttributes();

    expect($staleRun->restartIfFailed())->toBe($staleRun)
        ->and($staleRun->getAttributes())->toBe($attributes)
        ->and($staleRun->relationLoaded('items'))->toBeFalse()
        ->and($staleRun->items)->toHaveCount(0)
        ->and($run->fresh()->processed_items)->toBe(1);
});

it('restarts active and finished runs', function (?string $transition) {
    $run = Run::start();

    if ($transition) {
        $run->{$transition}();
    }

    $run->record();
    $run->restart();

    expect($run->refresh())
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(0)
        ->completed_at->toBeNull()
        ->failed_at->toBeNull()
        ->cancelled_at->toBeNull();
})->with([null, 'complete', 'fail', 'cancel']);

it('preserves unknown and empty totals when restarting', function (?int $total) {
    $run = Run::start(total: $total);
    $run->missed();
    $run->fail();

    $run->restart();

    expect($run->refresh())
        ->total_items->toBe($total)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->metadata->toBeNull()
        ->progress()->toBe(is_null($total) ? null : 0);
})->with([null, 0]);

it('preserves persisted configuration when restarting stale models', function () {
    $run = Run::start(metadata: ['source' => 'crm']);
    $staleRun = $run->fresh();

    $run->forceFill([
        'name' => 'Updated sync',
        'total_items' => 20,
        'metadata' => ['source' => 'backup'],
    ])->save();

    $run->missed(new RuntimeException('Unable to sync contact'));
    $run->fail();

    $staleRun->restart();

    expect($staleRun->refresh())
        ->name->toBe('Updated sync')
        ->total_items->toBe(20)
        ->metadata->toBe(['source' => 'backup'])
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->exception->toBeNull()
        ->status->toBe(RunStatus::Running)
        ->and($run->fresh()->status)->toBe(RunStatus::Running)
        ->and($staleRun->items)->toHaveCount(0);
});

it('only deletes ledger items belonging to the restarted run', function () {
    $run = Run::start();
    $otherRun = Run::start();

    $run->missed(new RuntimeException('Unable to sync contact'));
    $otherRun->missed(new RuntimeException('Unable to sync another contact'));

    $run->restart();

    expect($run->items)->toHaveCount(0)
        ->and($otherRun->items)->toHaveCount(1)
        ->and($otherRun->fresh()->processed_items)->toBe(1)
        ->and($otherRun->fresh()->failed_items)->toBe(1);
});

it('restores the same run from the original job payload after restarting', function () {
    $run = Run::start(total: 2);
    $payload = serialize(new RestartableJob($run));

    $run->missed(new RuntimeException('Unable to sync contact'));
    $run->fail();
    $run->restart();

    $job = unserialize($payload);

    expect($job->run->is($run))->toBeTrue()
        ->and($job->run)
        ->status->toBe(RunStatus::Running)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->and($job->run->items)->toHaveCount(0);

    $job->run->fail(new RuntimeException('Unable to finish restarted job'));

    expect($run->fresh())
        ->status->toBe(RunStatus::Failed)
        ->exception->toContain('Unable to finish restarted job')
        ->and(Run::query()->count())->toBe(1);
});

it('rolls back ledger deletion when restarting fails', function (string $method) {
    $run = Run::start();
    $run->missed(new RuntimeException('Unable to sync contact'));
    $run->fail();

    Run::saving(function () {
        throw new RuntimeException('Unable to save run');
    });

    expect(fn () => $run->{$method}())->toThrow(RuntimeException::class, 'Unable to save run');

    expect($run->fresh())
        ->status->toBe(RunStatus::Failed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->and($run->items()->count())->toBe(1);
})->with(['restart', 'restartIfFailed']);

it('increments processed items when recording models', function () {
    $run = Run::start(total: 1);
    $article = Article::query()->create();

    $item = $run->record($article);

    expect($item)
        ->status->toBe(RunStatus::Completed)
        ->resource->is($article)->toBeTrue()
        ->and($run->refresh()->processed_items)->toBe(1);
});

it('can record models without incrementing processed items', function () {
    $run = Run::start(total: 1);
    $article = Article::query()->create();

    $item = $run->record($article, count: false);

    expect($item)
        ->status->toBe(RunStatus::Completed)
        ->resource->is($article)->toBeTrue()
        ->and($run->refresh()->processed_items)->toBe(0)
        ->and($run->items)->toHaveCount(1);
});

it('records metadata without exposing run item attributes', function () {
    $run = Run::start(total: 1);
    $article = Article::query()->create();

    $item = $run->record($article, metadata: ['source' => 'import']);

    expect($item)
        ->metadata->toBe(['source' => 'import'])
        ->completed_at->not->toBeNull()
        ->failed_at->toBeNull();
});

it('can record processed items without ledger entries', function () {
    $run = Run::start(total: 1);

    $item = $run->record();

    expect($item)->toBeNull()
        ->and($run->refresh()->processed_items)->toBe(1)
        ->and($run->items)->toHaveCount(0);
});

it('respects morph maps when recording models', function () {
    Relation::morphMap([
        'article' => Article::class,
    ]);

    $run = Run::start(total: 1);
    $article = Article::query()->create();

    $item = $run->record($article);

    expect($item)
        ->resource_type->toBe('article')
        ->resource->is($article)->toBeTrue();
});

it('records missed items without a model', function () {
    $run = Run::start(total: 1);

    $item = $run->missed(
        new RuntimeException('Invalid row'),
        metadata: ['row' => 1],
    );

    expect($item)
        ->status->toBe(RunStatus::Failed)
        ->resource_id->toBeNull()
        ->metadata->toBe(['row' => 1])
        ->and($run->refresh()->processed_items)->toBe(1)
        ->and($run->failed_items)->toBe(1);
});

it('can record failed items without ledger entries', function () {
    $run = Run::start(total: 1);

    $item = $run->missed();

    expect($item)->toBeNull()
        ->and($run->refresh()->processed_items)->toBe(1)
        ->and($run->failed_items)->toBe(1)
        ->and($run->items)->toHaveCount(0);
});

it('can record missed items without incrementing counters', function () {
    $run = Run::start(total: 1);

    $item = $run->missed(
        new RuntimeException('Invalid row'),
        metadata: ['row' => 1],
        count: false,
    );

    expect($item->status)->toBe(RunStatus::Failed)
        ->and($run->refresh()->processed_items)->toBe(0)
        ->and($run->failed_items)->toBe(0);
});

it('requires recorded models to be persisted', function () {
    $run = Run::start();

    $run->record(new Article);
})->throws(InvalidArgumentException::class);

it('queries runs by status', function () {
    Run::start()->complete();
    Run::start()->fail();
    Run::start()->cancel();
    Run::query()->create();

    expect(DB::table('runs')->count())->toBe(4)
        ->and(Run::completed()->count())->toBe(1)
        ->and(Run::failed()->count())->toBe(1)
        ->and(Run::query()->cancelled()->count())->toBe(1)
        ->and(Run::active()->count())->toBe(1);
});

it('uses custom eloquent builders', function () {
    $run = Run::start();

    expect(Run::query())->toBeInstanceOf(RunBuilder::class)
        ->and($run->items()->getQuery())->toBeInstanceOf(RunItemBuilder::class);
});

it('adds a run relationship to models', function () {
    $article = Article::query()->create();

    Run::start(subject: $article);

    expect($article->runs)->toHaveCount(1);
});

it('keeps repeated lifecycle transitions idempotent', function () {
    $run = Run::start();

    expect($run->complete())->toBeTrue();

    $completedAt = $run->completed_at;

    expect($run->complete())->toBeTrue();

    expect($run)
        ->status->toBe(RunStatus::Completed)
        ->completed_at->toEqual($completedAt);
});

it('does not replace an existing terminal state', function () {
    $run = Run::start();

    expect($run->fail(new RuntimeException('Unable to finish')))->toBeTrue();

    $failedAt = $run->failed_at;

    expect($run->complete())->toBeFalse()
        ->and($run->cancel())->toBeFalse()
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($run->failed_at)->toEqual($failedAt)
        ->and($run->completed_at)->toBeNull()
        ->and($run->cancelled_at)->toBeNull()
        ->and($run->exception)->toContain('Unable to finish');
});

it('synchronizes stale models with the winning terminal state', function () {
    $run = Run::start();
    $staleRun = $run->fresh();

    expect($run->complete())->toBeTrue()
        ->and($staleRun->fail(new RuntimeException('Unable to finish')))->toBeFalse()
        ->and($staleRun->status)->toBe(RunStatus::Completed)
        ->and($staleRun->completed_at)->not->toBeNull()
        ->and($staleRun->failed_at)->toBeNull();

    expect($run->refresh()->status)->toBe(RunStatus::Completed);
});

it('reports batch-like item metrics', function () {
    $run = Run::start(total: 10);

    $run->incrementProcessed(4);

    expect($run)
        ->processedItems()->toBe(4)
        ->pendingItems()->toBe(6)
        ->progress()->toBe(40)
        ->and($run->finished())->toBeFalse()
        ->and($run->cancelled())->toBeFalse()
        ->and($run->hasFailures())->toBeFalse();
});

class RestartableJob
{
    use SerializesModels;

    public function __construct(
        public Run $run
    ) {}
}

class Article extends Model
{
    use HasRuns;

    protected $guarded = [];
}

enum RunName: string
{
    case ImportContacts = 'Import contacts';
}

enum RunType: string
{
    case Import = 'import';
}
