<?php

use DirectoryTree\Progress\Concerns\HasRuns;
use DirectoryTree\Progress\Enums\RunStatus;
use DirectoryTree\Progress\Models\Builders\RunBuilder;
use DirectoryTree\Progress\Models\Builders\RunItemBuilder;
use DirectoryTree\Progress\Models\Run;
use DirectoryTree\Progress\Models\RunItem;
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

it('advances progress by one or by a batch without recording ledger entries', function () {
    $run = Run::start(total: 10);

    expect($run->advance())->toBe(1)
        ->and($run->fresh()->processedItems())->toBe(1)
        ->and($run->fresh()->percentage())->toBe(10);

    expect($run->advance(9))->toBe(1)
        ->and($run->fresh()->processedItems())->toBe(10)
        ->and($run->fresh()->percentage())->toBe(100)
        ->and($run->fresh()->failed_items)->toBe(0)
        ->and($run->items()->count())->toBe(0);
});

it('does not change other run counters when advancing an unsaved run', function (string $method) {
    $first = Run::start(total: 10);
    $second = Run::start(total: 5);
    $unsaved = new Run;

    expect(fn () => $unsaved->{$method}())
        ->toThrow(InvalidArgumentException::class, 'The run must be saved before advancing it.');

    expect($first->fresh()->processedItems())->toBe(0)
        ->and($second->fresh()->processedItems())->toBe(0);
})->with(['advance', 'record']);

it('does not count failures on an unsaved run with an existing run ID', function (string $method) {
    $run = Run::start();
    $unsaved = new Run(['id' => $run->getKey()]);

    expect(fn () => $unsaved->{$method}())->toThrow(InvalidArgumentException::class);

    expect($run->refresh())
        ->processed_items->toBe(0)
        ->failed_items->toBe(0);
})->with(['incrementFailed', 'missed']);

it('stores runs and their ledger using the run tables and foreign key', function () {
    $run = Run::start();
    $article = Article::create();

    $item = $run->record($article);

    expect($run->getTable())->toBe('runs')
        ->and($item)->toBeInstanceOf(RunItem::class)
        ->and($item->getTable())->toBe('run_items')
        ->and($item->run_id)->toBe($run->getKey())
        ->and($item->run()->getForeignKeyName())->toBe('run_id')
        ->and($item->run->is($run))->toBeTrue()
        ->and($run->items->sole()->is($item))->toBeTrue();

    $run->delete();

    expect(RunItem::count())->toBe(0);
});

it('records and clears ledger items for a custom run model', function () {
    $run = ExtendedRun::start();
    $other = Run::start();
    $article = Article::create();

    $recorded = $run->record($article);
    $missed = $run->missed(new RuntimeException('Import failed'));
    $other->record($article);

    expect($recorded->run_id)->toBe($run->getKey())
        ->and($missed->run_id)->toBe($run->getKey())
        ->and($run->items()->count())->toBe(2);

    $run->fail();
    $run->restartIfFailed();

    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->processedItems())->toBe(0)
        ->and($run->items()->count())->toBe(0)
        ->and($other->items()->count())->toBe(1);
});

it('does not return an unsaved run when an observer vetoes creation', function () {
    Run::creating(fn () => false);

    expect(fn () => Run::start())->toThrow(RuntimeException::class)
        ->and(Run::count())->toBe(0);
});

it('starts progress', function () {
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

    expect($run->percentage())->toBe(0);
});

it('begins a pending run once without resetting its progress', function () {
    $run = ExtendedRun::create(['name' => 'Import contacts']);
    $item = $run->missed(metadata: ['row' => 1]);
    $stale = $run->fresh();

    expect($run->begin())->toBeTrue();

    $startedAt = $run->fresh()->started_at;

    ExtendedRun::updating(fn () => throw new RuntimeException('Unexpected second update'));

    expect($stale->begin())->toBeTrue()
        ->and($stale->status)->toBe(RunStatus::Running)
        ->and($stale->started_at)->toEqual($startedAt)
        ->and($stale->fresh())
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->and($stale->items()->sole()->is($item))->toBeTrue();
});

it('does not begin a run that has since finished', function (string $transition) {
    $run = Run::start();
    $stale = $run->fresh();
    $run->{$transition}();
    $before = $run->fresh()->getAttributes();

    expect($stale->begin())->toBeFalse()
        ->and($stale->getAttributes())->toBe($before)
        ->and($stale->fresh()->getAttributes())->toBe($before);
})->with(['complete', 'fail', 'cancel']);

it('starts progress with backed enum names and types', function () {
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

it('classifies active progress statuses', function () {
    expect(RunStatus::Pending->active())->toBeTrue()
        ->and(RunStatus::Running->active())->toBeTrue()
        ->and(RunStatus::Completed->active())->toBeFalse()
        ->and(RunStatus::Failed->active())->toBeFalse()
        ->and(RunStatus::Cancelled->active())->toBeFalse();
});

it('tracks progress', function () {
    $run = Run::start(total: 10);

    $run->advance(5);

    expect($run->refresh()->percentage())->toBe(50);
});

it('tracks indeterminate progress without a total', function () {
    $run = Run::start();

    $run->advance(5);

    expect($run->refresh())
        ->total_items->toBeNull()
        ->processedItems()->toBe(5)
        ->pendingItems()->toBeNull()
        ->percentage()->toBeNull();
});

it('distinguishes an unknown total from a known empty progress', function () {
    $run = Run::start(total: 0);
    $failedRun = Run::start(total: 0);
    $cancelledRun = Run::start(total: 0);

    $failedRun->fail();
    $cancelledRun->cancel();

    expect($run)
        ->total_items->toBe(0)
        ->pendingItems()->toBe(0)
        ->percentage()->toBe(0)
        ->and($failedRun->percentage())->toBe(0)
        ->and($cancelledRun->percentage())->toBe(0);
});

it('completes progress', function () {
    $run = Run::start();

    $run->complete();

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->completed_at->not->toBeNull();

    expect($run->percentage())->toBe(100);
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

it('starts progress with relationships and metadata', function (string $model) {
    $owner = Article::query()->create();
    $subject = Article::query()->create();

    $run = $model::start(
        name: 'Import contacts',
        total: 10,
        subject: $subject,
        owner: $owner,
        type: 'import',
        metadata: ['disk' => 'local'],
    );

    expect($run->refresh())
        ->name->toBe('Import contacts')
        ->type->toBe('import')
        ->status->toBe(RunStatus::Running)
        ->total_items->toBe(10)
        ->metadata->toBe(['disk' => 'local'])
        ->and($run->owner->is($owner))->toBeTrue()
        ->and($run->subject->is($subject))->toBeTrue();
})->with([
    'base model' => [Run::class],
    'guarded subclass' => [ExtendedRun::class],
]);

it('restarts progress without changing their identity or configuration', function () {
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

it('restarts failed run conditionally', function () {
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

it('preserves non-failed run when conditionally restarting', function (RunStatus $status) {
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
    $run->advance();

    $attributes = $run->fresh()->getAttributes();

    expect($staleRun->restartIfFailed())->toBe($staleRun)
        ->and($staleRun->getAttributes())->toBe($attributes)
        ->and($staleRun->relationLoaded('items'))->toBeFalse()
        ->and($staleRun->items)->toHaveCount(0)
        ->and($run->fresh()->processed_items)->toBe(1);
});

it('restarts active and finished progress', function (?string $transition) {
    $run = Run::start();

    if ($transition) {
        $run->{$transition}();
    }

    $run->advance();
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
        ->percentage()->toBe(is_null($total) ? null : 0);
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

it('only deletes ledger items belonging to the restarted progress', function () {
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

it('restores the same progress from the original job payload after restarting', function () {
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
        throw new RuntimeException('Unable to save progress');
    });

    expect(fn () => $run->{$method}())->toThrow(RuntimeException::class, 'Unable to save progress');

    expect($run->fresh())
        ->status->toBe(RunStatus::Failed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->and($run->items()->count())->toBe(1);
})->with(['restart', 'restartIfFailed']);

it('preserves the run and its ledger when an observer vetoes a restart', function (string $method) {
    $run = Run::start();
    $item = $run->missed(metadata: ['row' => 1]);
    $run->fail();

    Run::updating(fn () => false);

    expect(fn () => $run->{$method}())->toThrow(RuntimeException::class, 'The run could not be restarted.');

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->fresh())
        ->status->toBe(RunStatus::Failed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->and($run->items()->sole()->is($item))->toBeTrue();
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

it('records metadata without exposing progress item attributes', function () {
    $run = Run::start(total: 1);
    $article = Article::query()->create();

    $item = $run->record($article, metadata: ['source' => 'import']);

    expect($item)
        ->metadata->toBe(['source' => 'import'])
        ->completed_at->not->toBeNull()
        ->failed_at->toBeNull();
});

it('records successful metadata without a resource', function (bool $count) {
    $run = Run::start();
    $item = $run->record(metadata: ['row' => 42], count: $count);

    expect($run->items()->sole()->is($item))->toBeTrue()
        ->and($item->refresh())
        ->metadata->toBe(['row' => 42])
        ->status->toBe(RunStatus::Completed)
        ->resource_type->toBeNull()
        ->resource_id->toBeNull()
        ->and($run->refresh()->processedItems())->toBe($count ? 1 : 0);
})->with([true, false]);

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

it('queries progress by status', function () {
    Run::start()->complete();
    Run::start()->fail();
    Run::start()->cancel();
    Run::query()->create();

    expect(DB::table('runs')->count())->toBe(4)
        ->and(Run::completed()->count())->toBe(1)
        ->and(Run::failed()->count())->toBe(1)
        ->and(Run::cancelled()->count())->toBe(1)
        ->and(Run::active()->count())->toBe(1);
});

it('uses custom eloquent builders', function () {
    $run = Run::start();

    expect(Run::query())->toBeInstanceOf(RunBuilder::class)
        ->and($run->items()->getQuery())->toBeInstanceOf(RunItemBuilder::class);
});

it('adds a progress relationship to models', function () {
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

it('restores the run when an observer vetoes a terminal transition', function (string $method) {
    $run = Run::start();
    $before = $run->fresh()->getAttributes();

    Run::updating(fn () => false);

    expect(fn () => $method === 'fail' ? $run->fail(new RuntimeException('Job failed')) : $run->{$method}())
        ->toThrow(RuntimeException::class, 'The run could not be updated.');

    expect($run->getAttributes())->toBe($before)
        ->and($run->fresh()->getAttributes())->toBe($before);
})->with(['complete', 'fail', 'cancel']);

it('distinguishes a terminal run status from individual item failures', function (string $method) {
    $run = Run::start();
    $run->missed();
    $run->{$method}();

    expect($run->isFinished())->toBeTrue()
        ->and($run->isFailed())->toBe($method === 'fail')
        ->and($run->isCancelled())->toBe($method === 'cancel')
        ->and($run->hasFailures())->toBeTrue();
})->with(['complete', 'fail', 'cancel']);

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

    $run->advance(4);

    expect($run)
        ->processedItems()->toBe(4)
        ->pendingItems()->toBe(6)
        ->percentage()->toBe(40)
        ->and($run->isFinished())->toBeFalse()
        ->and($run->isCancelled())->toBeFalse()
        ->and($run->isFailed())->toBeFalse()
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

class ExtendedRun extends Run
{
    protected $table = 'runs';

    protected $fillable = ['name'];
}

enum RunName: string
{
    case ImportContacts = 'Import contacts';
}

enum RunType: string
{
    case Import = 'import';
}
