<?php

use DirectoryTree\Stride\Concerns\HasRuns;
use DirectoryTree\Stride\Enums\RunStatus;
use DirectoryTree\Stride\Models\Builders\RunBuilder;
use DirectoryTree\Stride\Models\Builders\RunItemBuilder;
use DirectoryTree\Stride\Models\Run;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
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
