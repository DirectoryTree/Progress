<?php

use DirectoryTree\Progress\Concerns\HasProgress;
use DirectoryTree\Progress\Enums\ProgressStatus;
use DirectoryTree\Progress\Models\Builders\ProgressBuilder;
use DirectoryTree\Progress\Models\Builders\ProgressItemBuilder;
use DirectoryTree\Progress\Models\Progress;
use DirectoryTree\Progress\Models\ProgressItem;
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
    $progress = Progress::start(total: 10);

    expect($progress->advance())->toBe(1)
        ->and($progress->fresh()->processedItems())->toBe(1)
        ->and($progress->fresh()->percentage())->toBe(10);

    expect($progress->advance(9))->toBe(1)
        ->and($progress->fresh()->processedItems())->toBe(10)
        ->and($progress->fresh()->percentage())->toBe(100)
        ->and($progress->fresh()->failed_items)->toBe(0)
        ->and($progress->items()->count())->toBe(0);
});

it('stores progress and its ledger using the progress table names and foreign key', function () {
    $progress = Progress::start();
    $article = Article::create();

    $item = $progress->record($article);

    expect($progress->getTable())->toBe('progress')
        ->and($item)->toBeInstanceOf(ProgressItem::class)
        ->and($item->getTable())->toBe('progress_items')
        ->and($item->progress_id)->toBe($progress->getKey())
        ->and($item->progress()->getForeignKeyName())->toBe('progress_id')
        ->and($item->progress->is($progress))->toBeTrue()
        ->and($progress->items->sole()->is($item))->toBeTrue();

    $progress->delete();

    expect(ProgressItem::count())->toBe(0);
});

it('starts progress', function () {
    $progress = Progress::start(
        name: 'Import contacts',
        total: 10,
    );

    expect($progress)
        ->status->toBe(ProgressStatus::Running)
        ->started_at->not->toBeNull()
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->processedItems()->toBe(0)
        ->hasFailures()->toBeFalse();

    expect($progress->percentage())->toBe(0);
});

it('starts progress with backed enum names and types', function () {
    $progress = Progress::start(
        name: ProgressName::ImportContacts,
        type: ProgressType::Import,
    );

    expect($progress)
        ->name->toBe('Import contacts')
        ->type->toBe('import');
});

it('derives statuses from lifecycle timestamps', function () {
    $progress = Progress::query()->create();

    expect(Schema::hasColumn('progress', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('progress_items', 'status'))->toBeFalse()
        ->and($progress->status)->toBe(ProgressStatus::Pending)
        ->and($progress->processedItems())->toBe(0)
        ->and($progress->hasFailures())->toBeFalse();

    $progress->forceFill(['started_at' => now()])->save();

    expect($progress->status)->toBe(ProgressStatus::Running);
});

it('classifies active progress statuses', function () {
    expect(ProgressStatus::Pending->active())->toBeTrue()
        ->and(ProgressStatus::Running->active())->toBeTrue()
        ->and(ProgressStatus::Completed->active())->toBeFalse()
        ->and(ProgressStatus::Failed->active())->toBeFalse()
        ->and(ProgressStatus::Cancelled->active())->toBeFalse();
});

it('tracks progress', function () {
    $progress = Progress::start(total: 10);

    $progress->advance(5);

    expect($progress->refresh()->percentage())->toBe(50);
});

it('tracks indeterminate progress without a total', function () {
    $progress = Progress::start();

    $progress->advance(5);

    expect($progress->refresh())
        ->total_items->toBeNull()
        ->processedItems()->toBe(5)
        ->pendingItems()->toBeNull()
        ->percentage()->toBeNull();
});

it('distinguishes an unknown total from a known empty progress', function () {
    $progress = Progress::start(total: 0);
    $failedProgress = Progress::start(total: 0);
    $cancelledProgress = Progress::start(total: 0);

    $failedProgress->fail();
    $cancelledProgress->cancel();

    expect($progress)
        ->total_items->toBe(0)
        ->pendingItems()->toBe(0)
        ->percentage()->toBe(0)
        ->and($failedProgress->percentage())->toBe(0)
        ->and($cancelledProgress->percentage())->toBe(0);
});

it('completes progress', function () {
    $progress = Progress::start();

    $progress->complete();

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Completed)
        ->completed_at->not->toBeNull();

    expect($progress->percentage())->toBe(100);
});

it('records missed models', function () {
    $progress = Progress::start(total: 1);

    $article = Article::query()->create();

    $resource = $progress->missed(
        new RuntimeException('Unable to process article'),
        item: $article,
    );

    expect($resource)
        ->status->toBe(ProgressStatus::Failed)
        ->resource->is($article)->toBeTrue();

    expect($progress)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->hasFailures()->toBeTrue()
        ->and($progress->fresh()->processed_items)->toBe(1)
        ->and($progress->fresh()->failed_items)->toBe(1);
});

it('starts progress with relationships and metadata', function () {
    $owner = Article::query()->create();
    $subject = Article::query()->create();

    $progress = Progress::start(
        name: 'Import contacts',
        total: 10,
        subject: $subject,
        owner: $owner,
        type: 'import',
        metadata: ['disk' => 'local'],
    );

    expect($progress)
        ->name->toBe('Import contacts')
        ->type->toBe('import')
        ->status->toBe(ProgressStatus::Running)
        ->total_items->toBe(10)
        ->metadata->toBe(['disk' => 'local'])
        ->and($progress->owner->is($owner))->toBeTrue()
        ->and($progress->subject->is($subject))->toBeTrue();
});

it('restarts progress without changing their identity or configuration', function () {
    $owner = Article::query()->create();
    $subject = Article::query()->create();

    $progress = Progress::start(
        name: 'Sync contacts',
        type: 'sync',
        total: 2,
        subject: $subject,
        owner: $owner,
        metadata: ['source' => 'crm'],
    );

    $progress->record($subject);
    $progress->missed(new RuntimeException('Unable to sync contact'));
    $progress->fail(new RuntimeException('Unable to finish sync'));
    $progress->load('items');

    $id = $progress->getKey();
    $createdAt = $progress->created_at;
    $startedAt = now()->subMinute();

    $progress->forceFill(['started_at' => $startedAt])->save();

    $restarted = $progress->restart();

    expect($restarted)->toBe($progress)
        ->and($progress->getKey())->toBe($id)
        ->and($progress->relationLoaded('items'))->toBeFalse()
        ->and($progress)
        ->name->toBe('Sync contacts')
        ->type->toBe('sync')
        ->total_items->toBe(2)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->metadata->toBe(['source' => 'crm'])
        ->exception->toBeNull()
        ->status->toBe(ProgressStatus::Running)
        ->completed_at->toBeNull()
        ->failed_at->toBeNull()
        ->cancelled_at->toBeNull()
        ->and($progress->created_at)->toEqual($createdAt)
        ->and($progress->started_at->greaterThan($startedAt))->toBeTrue()
        ->and($progress->owner->is($owner))->toBeTrue()
        ->and($progress->subject->is($subject))->toBeTrue()
        ->and($progress->items)->toHaveCount(0)
        ->and($progress->fresh()->processed_items)->toBe(0)
        ->and($progress->fresh()->failed_items)->toBe(0)
        ->and($progress->fresh()->status)->toBe(ProgressStatus::Running)
        ->and(Progress::query()->count())->toBe(1)
        ->and(DB::table('progress_items')->count())->toBe(0)
        ->and(Schema::hasColumn('progress', 'original_progress_id'))->toBeFalse()
        ->and(Schema::hasColumn('progress', 'attempt'))->toBeFalse();
});

it('restarts failed progress conditionally', function () {
    $progress = Progress::start(name: 'Sync contacts', total: 2, metadata: ['source' => 'crm']);
    $progress->missed(new RuntimeException('Unable to sync contact'), metadata: ['row' => 1]);
    $progress->fail(new RuntimeException('Unable to finish sync'));
    $progress->load('items');

    $id = $progress->getKey();
    $startedAt = now()->subMinute();
    $progress->forceFill(['started_at' => $startedAt])->save();

    expect($progress->restartIfFailed())->toBe($progress)
        ->and($progress->getKey())->toBe($id)
        ->and($progress->relationLoaded('items'))->toBeFalse()
        ->and($progress)
        ->name->toBe('Sync contacts')
        ->total_items->toBe(2)
        ->metadata->toBe(['source' => 'crm'])
        ->status->toBe(ProgressStatus::Running)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->exception->toBeNull()
        ->failed_at->toBeNull()
        ->and($progress->started_at->greaterThan($startedAt))->toBeTrue()
        ->and($progress->items)->toHaveCount(0)
        ->and($progress->fresh()->status)->toBe(ProgressStatus::Running)
        ->and(Progress::query()->count())->toBe(1);
});

it('preserves non-failed progress when conditionally restarting', function (ProgressStatus $status) {
    $progress = Progress::query()->create([
        'started_at' => $status === ProgressStatus::Pending ? null : now(),
        'completed_at' => $status === ProgressStatus::Completed ? now() : null,
        'cancelled_at' => $status === ProgressStatus::Cancelled ? now() : null,
    ]);
    $progress->missed(metadata: ['row' => 1]);

    $attributes = $progress->fresh()->getAttributes();

    expect($progress->restartIfFailed())->toBe($progress)
        ->and($progress->getAttributes())->toBe($attributes)
        ->and($progress->fresh()->getAttributes())->toBe($attributes)
        ->and($progress->status)->toBe($status)
        ->and($progress->items)->toHaveCount(1);
})->with([ProgressStatus::Pending, ProgressStatus::Running, ProgressStatus::Completed, ProgressStatus::Cancelled]);

it('conditionally restarts using persisted failures instead of stale state', function () {
    $progress = Progress::start();
    $staleProgress = $progress->fresh();

    $progress->missed(metadata: ['row' => 1]);
    $progress->fail();

    $staleProgress->restartIfFailed();

    expect($staleProgress)
        ->status->toBe(ProgressStatus::Running)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->and($staleProgress->items)->toHaveCount(0)
        ->and($progress->fresh()->status)->toBe(ProgressStatus::Running);
});

it('preserves restarted progress when conditionally restarting stale failed models', function () {
    $progress = Progress::start();
    $progress->missed(metadata: ['row' => 1]);
    $progress->fail();

    $staleProgress = $progress->fresh()->load('items');

    $progress->restartIfFailed();
    $progress->advance();

    $attributes = $progress->fresh()->getAttributes();

    expect($staleProgress->restartIfFailed())->toBe($staleProgress)
        ->and($staleProgress->getAttributes())->toBe($attributes)
        ->and($staleProgress->relationLoaded('items'))->toBeFalse()
        ->and($staleProgress->items)->toHaveCount(0)
        ->and($progress->fresh()->processed_items)->toBe(1);
});

it('restarts active and finished progress', function (?string $transition) {
    $progress = Progress::start();

    if ($transition) {
        $progress->{$transition}();
    }

    $progress->advance();
    $progress->restart();

    expect($progress->refresh())
        ->status->toBe(ProgressStatus::Running)
        ->processed_items->toBe(0)
        ->completed_at->toBeNull()
        ->failed_at->toBeNull()
        ->cancelled_at->toBeNull();
})->with([null, 'complete', 'fail', 'cancel']);

it('preserves unknown and empty totals when restarting', function (?int $total) {
    $progress = Progress::start(total: $total);
    $progress->missed();
    $progress->fail();

    $progress->restart();

    expect($progress->refresh())
        ->total_items->toBe($total)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->metadata->toBeNull()
        ->percentage()->toBe(is_null($total) ? null : 0);
})->with([null, 0]);

it('preserves persisted configuration when restarting stale models', function () {
    $progress = Progress::start(metadata: ['source' => 'crm']);
    $staleProgress = $progress->fresh();

    $progress->forceFill([
        'name' => 'Updated sync',
        'total_items' => 20,
        'metadata' => ['source' => 'backup'],
    ])->save();

    $progress->missed(new RuntimeException('Unable to sync contact'));
    $progress->fail();

    $staleProgress->restart();

    expect($staleProgress->refresh())
        ->name->toBe('Updated sync')
        ->total_items->toBe(20)
        ->metadata->toBe(['source' => 'backup'])
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->exception->toBeNull()
        ->status->toBe(ProgressStatus::Running)
        ->and($progress->fresh()->status)->toBe(ProgressStatus::Running)
        ->and($staleProgress->items)->toHaveCount(0);
});

it('only deletes ledger items belonging to the restarted progress', function () {
    $progress = Progress::start();
    $otherProgress = Progress::start();

    $progress->missed(new RuntimeException('Unable to sync contact'));
    $otherProgress->missed(new RuntimeException('Unable to sync another contact'));

    $progress->restart();

    expect($progress->items)->toHaveCount(0)
        ->and($otherProgress->items)->toHaveCount(1)
        ->and($otherProgress->fresh()->processed_items)->toBe(1)
        ->and($otherProgress->fresh()->failed_items)->toBe(1);
});

it('restores the same progress from the original job payload after restarting', function () {
    $progress = Progress::start(total: 2);
    $payload = serialize(new RestartableJob($progress));

    $progress->missed(new RuntimeException('Unable to sync contact'));
    $progress->fail();
    $progress->restart();

    $job = unserialize($payload);

    expect($job->progress->is($progress))->toBeTrue()
        ->and($job->progress)
        ->status->toBe(ProgressStatus::Running)
        ->processed_items->toBe(0)
        ->failed_items->toBe(0)
        ->and($job->progress->items)->toHaveCount(0);

    $job->progress->fail(new RuntimeException('Unable to finish restarted job'));

    expect($progress->fresh())
        ->status->toBe(ProgressStatus::Failed)
        ->exception->toContain('Unable to finish restarted job')
        ->and(Progress::query()->count())->toBe(1);
});

it('rolls back ledger deletion when restarting fails', function (string $method) {
    $progress = Progress::start();
    $progress->missed(new RuntimeException('Unable to sync contact'));
    $progress->fail();

    Progress::saving(function () {
        throw new RuntimeException('Unable to save progress');
    });

    expect(fn () => $progress->{$method}())->toThrow(RuntimeException::class, 'Unable to save progress');

    expect($progress->fresh())
        ->status->toBe(ProgressStatus::Failed)
        ->processed_items->toBe(1)
        ->failed_items->toBe(1)
        ->and($progress->items()->count())->toBe(1);
})->with(['restart', 'restartIfFailed']);

it('increments processed items when recording models', function () {
    $progress = Progress::start(total: 1);
    $article = Article::query()->create();

    $item = $progress->record($article);

    expect($item)
        ->status->toBe(ProgressStatus::Completed)
        ->resource->is($article)->toBeTrue()
        ->and($progress->refresh()->processed_items)->toBe(1);
});

it('can record models without incrementing processed items', function () {
    $progress = Progress::start(total: 1);
    $article = Article::query()->create();

    $item = $progress->record($article, count: false);

    expect($item)
        ->status->toBe(ProgressStatus::Completed)
        ->resource->is($article)->toBeTrue()
        ->and($progress->refresh()->processed_items)->toBe(0)
        ->and($progress->items)->toHaveCount(1);
});

it('records metadata without exposing progress item attributes', function () {
    $progress = Progress::start(total: 1);
    $article = Article::query()->create();

    $item = $progress->record($article, metadata: ['source' => 'import']);

    expect($item)
        ->metadata->toBe(['source' => 'import'])
        ->completed_at->not->toBeNull()
        ->failed_at->toBeNull();
});

it('can record processed items without ledger entries', function () {
    $progress = Progress::start(total: 1);

    $item = $progress->record();

    expect($item)->toBeNull()
        ->and($progress->refresh()->processed_items)->toBe(1)
        ->and($progress->items)->toHaveCount(0);
});

it('respects morph maps when recording models', function () {
    Relation::morphMap([
        'article' => Article::class,
    ]);

    $progress = Progress::start(total: 1);
    $article = Article::query()->create();

    $item = $progress->record($article);

    expect($item)
        ->resource_type->toBe('article')
        ->resource->is($article)->toBeTrue();
});

it('records missed items without a model', function () {
    $progress = Progress::start(total: 1);

    $item = $progress->missed(
        new RuntimeException('Invalid row'),
        metadata: ['row' => 1],
    );

    expect($item)
        ->status->toBe(ProgressStatus::Failed)
        ->resource_id->toBeNull()
        ->metadata->toBe(['row' => 1])
        ->and($progress->refresh()->processed_items)->toBe(1)
        ->and($progress->failed_items)->toBe(1);
});

it('can record failed items without ledger entries', function () {
    $progress = Progress::start(total: 1);

    $item = $progress->missed();

    expect($item)->toBeNull()
        ->and($progress->refresh()->processed_items)->toBe(1)
        ->and($progress->failed_items)->toBe(1)
        ->and($progress->items)->toHaveCount(0);
});

it('can record missed items without incrementing counters', function () {
    $progress = Progress::start(total: 1);

    $item = $progress->missed(
        new RuntimeException('Invalid row'),
        metadata: ['row' => 1],
        count: false,
    );

    expect($item->status)->toBe(ProgressStatus::Failed)
        ->and($progress->refresh()->processed_items)->toBe(0)
        ->and($progress->failed_items)->toBe(0);
});

it('requires recorded models to be persisted', function () {
    $progress = Progress::start();

    $progress->record(new Article);
})->throws(InvalidArgumentException::class);

it('queries progress by status', function () {
    Progress::start()->complete();
    Progress::start()->fail();
    Progress::start()->cancel();
    Progress::query()->create();

    expect(DB::table('progress')->count())->toBe(4)
        ->and(Progress::completed()->count())->toBe(1)
        ->and(Progress::failed()->count())->toBe(1)
        ->and(Progress::query()->cancelled()->count())->toBe(1)
        ->and(Progress::active()->count())->toBe(1);
});

it('uses custom eloquent builders', function () {
    $progress = Progress::start();

    expect(Progress::query())->toBeInstanceOf(ProgressBuilder::class)
        ->and($progress->items()->getQuery())->toBeInstanceOf(ProgressItemBuilder::class);
});

it('adds a progress relationship to models', function () {
    $article = Article::query()->create();

    Progress::start(subject: $article);

    expect($article->progress)->toHaveCount(1);
});

it('keeps repeated lifecycle transitions idempotent', function () {
    $progress = Progress::start();

    expect($progress->complete())->toBeTrue();

    $completedAt = $progress->completed_at;

    expect($progress->complete())->toBeTrue();

    expect($progress)
        ->status->toBe(ProgressStatus::Completed)
        ->completed_at->toEqual($completedAt);
});

it('does not replace an existing terminal state', function () {
    $progress = Progress::start();

    expect($progress->fail(new RuntimeException('Unable to finish')))->toBeTrue();

    $failedAt = $progress->failed_at;

    expect($progress->complete())->toBeFalse()
        ->and($progress->cancel())->toBeFalse()
        ->and($progress->status)->toBe(ProgressStatus::Failed)
        ->and($progress->failed_at)->toEqual($failedAt)
        ->and($progress->completed_at)->toBeNull()
        ->and($progress->cancelled_at)->toBeNull()
        ->and($progress->exception)->toContain('Unable to finish');
});

it('synchronizes stale models with the winning terminal state', function () {
    $progress = Progress::start();
    $staleProgress = $progress->fresh();

    expect($progress->complete())->toBeTrue()
        ->and($staleProgress->fail(new RuntimeException('Unable to finish')))->toBeFalse()
        ->and($staleProgress->status)->toBe(ProgressStatus::Completed)
        ->and($staleProgress->completed_at)->not->toBeNull()
        ->and($staleProgress->failed_at)->toBeNull();

    expect($progress->refresh()->status)->toBe(ProgressStatus::Completed);
});

it('reports batch-like item metrics', function () {
    $progress = Progress::start(total: 10);

    $progress->advance(4);

    expect($progress)
        ->processedItems()->toBe(4)
        ->pendingItems()->toBe(6)
        ->percentage()->toBe(40)
        ->and($progress->finished())->toBeFalse()
        ->and($progress->cancelled())->toBeFalse()
        ->and($progress->hasFailures())->toBeFalse();
});

class RestartableJob
{
    use SerializesModels;

    public function __construct(
        public Progress $progress
    ) {}
}

class Article extends Model
{
    use HasProgress;

    protected $guarded = [];
}

enum ProgressName: string
{
    case ImportContacts = 'Import contacts';
}

enum ProgressType: string
{
    case Import = 'import';
}
