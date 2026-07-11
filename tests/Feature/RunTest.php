<?php

use DirectoryTree\Stride\Concerns\HasRuns;
use DirectoryTree\Stride\Concerns\InteractsWithRun;
use DirectoryTree\Stride\Enums\RunStatus;
use DirectoryTree\Stride\Models\Builders\RunBuilder;
use DirectoryTree\Stride\Models\Builders\RunItemBuilder;
use DirectoryTree\Stride\Models\Run;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Bus;
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
        ->started_at->not->toBeNull();

    expect($run->progress())->toBe(0);
});

it('derives statuses from lifecycle timestamps', function () {
    $run = Run::query()->create();

    expect(Schema::hasColumn('runs', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('run_items', 'status'))->toBeFalse()
        ->and($run->status)->toBe(RunStatus::Pending);

    $run->forceFill(['started_at' => now()])->save();

    expect($run->status)->toBe(RunStatus::Running);
});

it('tracks progress', function () {
    $run = Run::start(total: 10);

    $run->incrementProcessed(5);

    expect($run->refresh()->progress())->toBe(50);
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

    expect($run->refresh())
        ->processed_items->toBe(1)
        ->failed_items->toBe(1);
});

it('starts runs with relationships and metadata', function () {
    $owner = Article::query()->create();
    $runnable = Article::query()->create();

    $run = Run::start(
        name: 'Import contacts',
        total: 10,
        runnable: $runnable,
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
        ->and($run->runnable->is($runnable))->toBeTrue();
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
    Run::query()->create();

    expect(DB::table('runs')->count())->toBe(3)
        ->and(Run::completed()->count())->toBe(1)
        ->and(Run::failed()->count())->toBe(1)
        ->and(Run::active()->count())->toBe(1);
});

it('uses custom eloquent builders', function () {
    $run = Run::start();

    expect(Run::query())->toBeInstanceOf(RunBuilder::class)
        ->and($run->items()->getQuery())->toBeInstanceOf(RunItemBuilder::class);
});

it('adds a run relationship to models', function () {
    $article = Article::query()->create();

    Run::start(runnable: $article);

    expect($article->runs)->toHaveCount(1);
});

it('clears opposing lifecycle timestamps when transitioning', function () {
    $run = Run::start();

    $run->fail(new RuntimeException('Unable to finish'));
    $run->complete();

    expect($run->refresh())
        ->status->toBe(RunStatus::Completed)
        ->failed_at->toBeNull()
        ->completed_at->not->toBeNull();

    $run->cancel();

    expect($run->refresh())
        ->status->toBe(RunStatus::Cancelled)
        ->completed_at->toBeNull()
        ->cancelled_at->not->toBeNull();
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

it('allows jobs to interact with runs', function () {
    $run = Run::start();

    $job = (new ImportContactsJob)->withRun($run);

    expect($job)
        ->runId->toBe($run->id)
        ->runActive()->toBeTrue()
        ->and($job->run()->is($run))->toBeTrue();
});

it('proxies withRun through pending dispatches', function () {
    Bus::fake();

    $run = Run::start();

    $pending = ImportContactsJob::dispatch()->withRun($run);

    expect($pending->getJob()->runId)->toBe($run->id);

    unset($pending);

    Bus::assertDispatched(
        ImportContactsJob::class,
        fn (ImportContactsJob $job) => $job->runId === $run->id,
    );
});

class Article extends Model
{
    use HasRuns;

    protected $guarded = [];
}

class ImportContactsJob
{
    use Dispatchable;
    use InteractsWithRun;
}
