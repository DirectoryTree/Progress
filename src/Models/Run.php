<?php

namespace DirectoryTree\Progress\Models;

use BackedEnum;
use DirectoryTree\Progress\Enums\RunItemStatus;
use DirectoryTree\Progress\Enums\RunStatus;
use DirectoryTree\Progress\Models\Builders\RunBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class Run extends Model
{
    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'processed_items' => 0,
        'failed_items' => 0,
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'failed_items' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Create a new Eloquent query builder for the model.
     */
    public function newEloquentBuilder($query): RunBuilder
    {
        return new RunBuilder($query);
    }

    /**
     * Create and start tracking progress.
     */
    public static function start(
        BackedEnum|string|null $name = null,
        BackedEnum|string|null $type = null,
        ?int $total = null,
        ?Model $subject = null,
        ?Model $owner = null,
        array $metadata = [],
    ): static {
        if ($total < 0) {
            throw new InvalidArgumentException('The total must not be negative.');
        }

        $run = new static;

        $run->forceFill([
            'name' => $name instanceof BackedEnum ? (string) $name->value : $name,
            'type' => $type instanceof BackedEnum ? (string) $type->value : $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'total_items' => $total,
            'metadata' => $metadata ?: null,
            'started_at' => $run->freshTimestamp(),
        ]);

        if (! $run->save()) {
            throw new RuntimeException('The run could not be started.');
        }

        return $run;
    }

    /**
     * Begin a pending run, or continue an already running one.
     */
    public function begin(): bool
    {
        return $this->getConnection()->transaction(function () {
            $run = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

            $this->setRawAttributes($run->getAttributes(), true);

            if ($this->isFinished()) {
                return false;
            }

            if (! is_null($this->started_at)) {
                return true;
            }

            if (! $this->forceFill(['started_at' => $this->freshTimestamp()])->save()) {
                $this->setRawAttributes($run->getAttributes(), true);

                throw new RuntimeException('The run could not be started.');
            }

            return true;
        });
    }

    /**
     * Clear the counters and ledger and start tracking again.
     */
    public function restart(): static
    {
        return $this->restartWhen();
    }

    /**
     * Restart the run only if it has failed.
     */
    public function restartIfFailed(): static
    {
        return $this->restartWhen(RunStatus::Failed);
    }

    /**
     * Restart the run when its persisted status matches the given status.
     */
    protected function restartWhen(?RunStatus $status = null): static
    {
        return $this->getConnection()->transaction(function () use ($status) {
            $run = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

            $this->setRawAttributes($run->getAttributes(), true)->unsetRelations();

            if (! is_null($status) && $this->status !== $status) {
                return $this;
            }

            $this->items()->delete();

            $this->forceFill([
                'processed_items' => 0,
                'failed_items' => 0,
                'exception' => null,
                'started_at' => $this->freshTimestamp(),
                'completed_at' => null,
                'failed_at' => null,
                'cancelled_at' => null,
            ]);

            if (! $this->save()) {
                $this->setRawAttributes($run->getAttributes(), true);

                throw new RuntimeException('The run could not be restarted.');
            }

            return $this;
        });
    }

    /**
     * The model or process being tracked.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The model that owns this run.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The items tracked by this run.
     */
    public function items(): HasMany
    {
        return $this->hasMany(RunItem::class, 'run_id');
    }

    /**
     * Determine if the run is active.
     */
    public function isActive(): bool
    {
        return $this->status->active();
    }

    /**
     * Determine if the run has finished.
     */
    public function isFinished(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Determine if the run has completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === RunStatus::Completed;
    }

    /**
     * Determine if the run has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === RunStatus::Cancelled;
    }

    /**
     * Determine if the run itself has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === RunStatus::Failed;
    }

    /**
     * Determine if the run has failed items.
     */
    public function hasFailures(): bool
    {
        return $this->failed_items > 0;
    }

    /**
     * Get the total number of items to process, if known.
     */
    public function totalItems(): ?int
    {
        return $this->total_items;
    }

    /**
     * Get the total number of items that have been processed.
     */
    public function processedItems(): int
    {
        return $this->processed_items;
    }

    /**
     * Get the number of processed items counted as failures.
     */
    public function failedItems(): int
    {
        return $this->failed_items;
    }

    /**
     * Get the total number of items that are still pending.
     */
    public function pendingItems(): ?int
    {
        if (is_null($this->total_items)) {
            return null;
        }

        return max(0, $this->total_items - $this->processed_items);
    }

    /**
     * Get the percentage of items that have been processed.
     *
     * @return int<0, 100>|null
     */
    public function percentage(): ?int
    {
        if ($this->status === RunStatus::Completed) {
            return 100;
        }

        if (is_null($this->total_items)) {
            return null;
        }

        if ($this->total_items === 0) {
            return 0;
        }

        return (int) min(100, round(($this->processed_items / $this->total_items) * 100));
    }

    /**
     * Get the status derived from the lifecycle timestamps.
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn () => match (true) {
            ! is_null($this->cancelled_at) => RunStatus::Cancelled,
            ! is_null($this->failed_at) => RunStatus::Failed,
            ! is_null($this->completed_at) => RunStatus::Completed,
            ! is_null($this->started_at) => RunStatus::Running,
            default => RunStatus::Pending,
        })->withoutObjectCaching();
    }

    /**
     * Mark the run as completed.
     */
    public function complete(): bool
    {
        return $this->transitionTo('completed_at');
    }

    /**
     * Mark the run as failed.
     */
    public function fail(?Throwable $exception = null): bool
    {
        return $this->transitionTo('failed_at', $exception ? [
            'exception' => (string) $exception,
        ] : []);
    }

    /**
     * Mark the run as cancelled.
     */
    public function cancel(): bool
    {
        return $this->transitionTo('cancelled_at');
    }

    /**
     * Transition the run to a terminal state.
     */
    protected function transitionTo(string $column, array $attributes = []): bool
    {
        return $this->getConnection()->transaction(function () use ($column, $attributes) {
            $run = $this->newQuery()->lockForUpdate()->findOrFail($this->getKey());

            $this->setRawAttributes($run->getAttributes(), true);

            if (! is_null($this->{$column})) {
                return true;
            }

            if ($this->isFinished()) {
                return false;
            }

            $this->forceFill([
                ...$attributes,
                $column => $this->freshTimestamp(),
            ]);

            if (! $this->save()) {
                $this->setRawAttributes($run->getAttributes(), true);

                throw new RuntimeException('The run could not be updated.');
            }

            return true;
        });
    }

    /**
     * Advance the processed item count, including any failures in the batch.
     */
    public function advance(int $amount = 1, int $failed = 0): int
    {
        if (! $this->exists) {
            throw new InvalidArgumentException('The run must be saved before advancing it.');
        }

        if ($amount < 0) {
            throw new InvalidArgumentException('The amount must not be negative.');
        }

        if ($failed < 0 || $failed > $amount) {
            throw new InvalidArgumentException('The failed count must be between zero and the processed amount.');
        }

        if ($amount === 0) {
            return 0;
        }

        $updated = $this->incrementCounters($amount, $failed);

        if ($updated) {
            $this->syncCounters($amount, $failed);
        }

        return $updated;
    }

    /**
     * Record a processed item for the run.
     */
    public function record(?Model $item = null, array $metadata = [], bool $count = true): ?RunItem
    {
        if (! $item && ! $metadata) {
            if ($count) {
                $this->advance();
            }

            return null;
        }

        if ($item && ! $item->exists) {
            throw new InvalidArgumentException('Run items must be persisted Eloquent models. Use missed() to record failures without a model.');
        }

        return $this->storeItem([
            'resource_type' => $item?->getMorphClass(),
            'resource_id' => $item?->getKey(),
            'metadata' => $metadata ?: null,
            'status' => RunItemStatus::Completed,
        ], $count);
    }

    /**
     * Record a missed item for the run.
     */
    public function missed(
        ?Throwable $exception = null,
        ?Model $item = null,
        array $metadata = [],
        bool $count = true,
    ): ?RunItem {
        if (! $exception && ! $item && ! $metadata) {
            if ($count) {
                $this->advance(failed: 1);
            }

            return null;
        }

        if ($item && ! $item->exists) {
            throw new InvalidArgumentException('Run items must be persisted Eloquent models.');
        }

        return $this->storeItem([
            'resource_type' => $item?->getMorphClass(),
            'resource_id' => $item?->getKey(),
            'exception' => $exception ? (string) $exception : null,
            'metadata' => $metadata ?: null,
            'status' => RunItemStatus::Failed,
        ], $count);
    }

    /**
     * Store a ledger item and its optional counter update together.
     */
    protected function storeItem(array $attributes, bool $count): RunItem
    {
        if (! $this->exists) {
            throw new InvalidArgumentException('The run must be saved before recording items.');
        }

        $failed = $attributes['status'] === RunItemStatus::Failed ? 1 : 0;

        $item = $this->getConnection()->transaction(function () use ($attributes, $count, $failed) {
            // Update the parent before the insert takes a shared foreign key lock.
            if ($count && ! $this->incrementCounters(1, $failed)) {
                throw new RuntimeException('The run counters could not be updated.');
            }

            $item = $this->items()->make($attributes);

            if (! $item->save()) {
                throw new RuntimeException('The run item could not be recorded.');
            }

            return $item;
        });

        if ($count) {
            $this->syncCounters(1, $failed);
        }

        return $item;
    }

    /**
     * Increment both counters in a single database update.
     */
    protected function incrementCounters(int $amount, int $failed): int
    {
        return $this->newQuery()->whereKey($this->getKey())->toBase()->incrementEach([
            'processed_items' => $amount,
            'failed_items' => $failed,
        ], $this->usesTimestamps() && $this->getUpdatedAtColumn() ? [
            $this->getUpdatedAtColumn() => $this->freshTimestampString(),
        ] : []);
    }

    /**
     * Synchronize committed counters without discarding other pending edits.
     */
    protected function syncCounters(int $amount, int $failed): void
    {
        $this->processed_items = $this->getRawOriginal('processed_items') + $amount;
        $this->failed_items = $this->getRawOriginal('failed_items') + $failed;

        $this->syncOriginalAttributes(['processed_items', 'failed_items']);
    }
}
