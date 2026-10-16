<?php

use DirectoryTree\Progress\Enums\ProgressStatus;
use DirectoryTree\Progress\Middleware\RestartFailedProgress;
use DirectoryTree\Progress\Middleware\SkipIfProgressFinished;
use DirectoryTree\Progress\Models\Progress;
use Illuminate\Pipeline\Pipeline;

it('skips finished progress using their persisted state', function (string $transition, ProgressStatus $status) {
    $progress = Progress::start();
    $progress->missed(metadata: ['row' => 1]);
    $middleware = new SkipIfProgressFinished($progress);

    $progress->fresh()->{$transition}();

    $attributes = $progress->fresh()->getAttributes();

    $result = $middleware->handle((object) ['progress' => $progress], function () {
        throw new RuntimeException('The job should not execute');
    });

    expect($result)->toBeNull()
        ->and($progress->status)->toBe($status)
        ->and($progress->getAttributes())->toBe($attributes)
        ->and($progress->fresh()->getAttributes())->toBe($attributes)
        ->and($progress->items)->toHaveCount(1);
})->with([
    'failed' => ['fail', ProgressStatus::Failed],
    'completed' => ['complete', ProgressStatus::Completed],
    'cancelled' => ['cancel', ProgressStatus::Cancelled],
]);

it('continues handling pending and running progress', function (ProgressStatus $status) {
    $progress = $status === ProgressStatus::Pending ? Progress::query()->create() : Progress::start();
    $progress->missed(metadata: ['row' => 1]);

    $attributes = $progress->fresh()->getAttributes();
    $job = (object) ['progress' => $progress];

    $result = (new SkipIfProgressFinished($progress))->handle($job, function ($command) use ($job) {
        expect($command)->toBe($job);

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($progress->status)->toBe($status)
        ->and($progress->getAttributes())->toBe($attributes)
        ->and($progress->items)->toHaveCount(1);
})->with([ProgressStatus::Pending, ProgressStatus::Running]);

it('continues handling progress restarted after the model was loaded', function () {
    $progress = Progress::start();
    $progress->fail();
    $middleware = new SkipIfProgressFinished($progress);

    $persisted = $progress->fresh();
    $persisted->restart();
    $persisted->advance();

    $result = $middleware->handle((object) ['progress' => $progress], function ($job) {
        expect($job->progress)
            ->status->toBe(ProgressStatus::Running)
            ->processed_items->toBe(1);

        return 'handled';
    });

    expect($result)->toBe('handled');
});

it('handles failed progress when restarting precedes skipping', function () {
    $progress = Progress::start();
    $progress->missed(metadata: ['row' => 1]);
    $progress->fail();

    $job = (object) ['progress' => $progress];

    $result = app(Pipeline::class)->send($job)->through([
        new RestartFailedProgress($progress),
        new SkipIfProgressFinished($progress),
    ])->then(function ($command) use ($job) {
        expect($command)->toBe($job)
            ->and($command->progress)
            ->status->toBe(ProgressStatus::Running)
            ->processed_items->toBe(0)
            ->failed_items->toBe(0)
            ->and($command->progress->items)->toHaveCount(0);

        $command->progress->advance();

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($progress->fresh()->processed_items)->toBe(1);
});
