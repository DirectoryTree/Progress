<?php

use DirectoryTree\Stride\Enums\RunStatus;
use DirectoryTree\Stride\Middleware\RestartFailedRun;
use DirectoryTree\Stride\Middleware\SkipIfRunFinished;
use DirectoryTree\Stride\Models\Run;
use Illuminate\Pipeline\Pipeline;

it('skips finished runs using their persisted state', function (string $transition, RunStatus $status) {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $middleware = new SkipIfRunFinished($run);

    $run->fresh()->{$transition}();

    $attributes = $run->fresh()->getAttributes();

    $result = $middleware->handle((object) ['run' => $run], function () {
        throw new RuntimeException('The job should not execute');
    });

    expect($result)->toBeNull()
        ->and($run->status)->toBe($status)
        ->and($run->getAttributes())->toBe($attributes)
        ->and($run->fresh()->getAttributes())->toBe($attributes)
        ->and($run->items)->toHaveCount(1);
})->with([
    'failed' => ['fail', RunStatus::Failed],
    'completed' => ['complete', RunStatus::Completed],
    'cancelled' => ['cancel', RunStatus::Cancelled],
]);

it('continues handling pending and running runs', function (RunStatus $status) {
    $run = $status === RunStatus::Pending ? Run::query()->create() : Run::start();
    $run->missed(metadata: ['row' => 1]);

    $attributes = $run->fresh()->getAttributes();
    $job = (object) ['run' => $run];

    $result = (new SkipIfRunFinished($run))->handle($job, function ($command) use ($job) {
        expect($command)->toBe($job);

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($run->status)->toBe($status)
        ->and($run->getAttributes())->toBe($attributes)
        ->and($run->items)->toHaveCount(1);
})->with([RunStatus::Pending, RunStatus::Running]);

it('continues handling runs restarted after the model was loaded', function () {
    $run = Run::start();
    $run->fail();
    $middleware = new SkipIfRunFinished($run);

    $persisted = $run->fresh();
    $persisted->restart();
    $persisted->record();

    $result = $middleware->handle((object) ['run' => $run], function ($job) {
        expect($job->run)
            ->status->toBe(RunStatus::Running)
            ->processed_items->toBe(1);

        return 'handled';
    });

    expect($result)->toBe('handled');
});

it('handles failed runs when restarting precedes skipping', function () {
    $run = Run::start();
    $run->missed(metadata: ['row' => 1]);
    $run->fail();

    $job = (object) ['run' => $run];

    $result = app(Pipeline::class)->send($job)->through([
        new RestartFailedRun($run),
        new SkipIfRunFinished($run),
    ])->then(function ($command) use ($job) {
        expect($command)->toBe($job)
            ->and($command->run)
            ->status->toBe(RunStatus::Running)
            ->processed_items->toBe(0)
            ->failed_items->toBe(0)
            ->and($command->run->items)->toHaveCount(0);

        $command->run->record();

        return 'handled';
    });

    expect($result)->toBe('handled')
        ->and($run->fresh()->processed_items)->toBe(1);
});
