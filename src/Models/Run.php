<?php

namespace DirectoryTree\Progress\Models;

use BackedEnum;
use DirectoryTree\Progress\Enums\RunStatus;
use DirectoryTree\Progress\Models\Builders\RunBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use Throwable;

class Run extends Model
{
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
     * The attributes that aren't mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

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
        $run = new static;

        $run->fill([
            'name' => $name instanceof BackedEnum ? (string) $name->value : $name,
            'type' => $type instanceof BackedEnum ? (string) $type->value : $type,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getKey(),
            'total_items' => $total,
            'metadata' => $metadata ?: null,
            'started_at' => $run->freshTimestamp(),
        ])->save();

        return $run;
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
            ])->save();

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
    public function finished(): bool
    {
        return ! is_null($this->failed_at)
            || ! is_null($this->cancelled_at)
            || ! is_null($this->completed_at);
    }

    /**
     * Determine if the run has been cancelled.
     */
    public function cancelled(): bool
    {
        return ! is_null($this->cancelled_at);
    }

    /**
     * Determine if the run has failed items.
     */
    public function hasFailures(): bool
    {
        return $this->failed_items > 0;
    }

    /**
     * Get the total number of items that have been processed.
     */
    public function processedItems(): int
    {
        return $this->processed_items;
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

            if ($this->finished()) {
                return false;
            }

            return $this->forceFill([
                ...$attributes,
                $column => $this->freshTimestamp(),
            ])->save();
        });
    }

    /**
     * Advance the processed item count.
     */
    public function advance(int $amount = 1): int
    {
        if (! $this->exists) {
            throw new InvalidArgumentException('The run must be saved before advancing it.');
        }

        return $this->increment('processed_items', $amount);
    }

    /**
     * Record a processed item for the run.
     */
    public function record(?Model $item = null, array $metadata = [], bool $count = true): ?RunItem
    {
        if (! $item) {
            if ($count) {
                $this->advance();
            }

            return null;
        }

        if (! $item->exists) {
            throw new InvalidArgumentException('Run items must be persisted Eloquent models. Use missed() to record failures without a model.');
        }

        return $this->getConnection()->transaction(function () use ($item, $metadata, $count) {
            if ($count) {
                $this->lockForCounterUpdate();
            }

            /** @var RunItem $record */
            $record = $this->items()->create([
                'resource_type' => $item->getMorphClass(),
                'resource_id' => $item->getKey(),
                'metadata' => $metadata ?: null,
                'completed_at' => $this->freshTimestamp(),
            ]);

            if ($count) {
                $this->advance();
            }

            return $record;
        });
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
                $this->incrementFailed();
            }

            return null;
        }

        if ($item && ! $item->exists) {
            throw new InvalidArgumentException('Run items must be persisted Eloquent models.');
        }

        return $this->getConnection()->transaction(function () use ($exception, $item, $metadata, $count) {
            if ($count) {
                $this->lockForCounterUpdate();
            }

            /** @var RunItem $record */
            $record = $this->items()->create([
                'resource_type' => $item?->getMorphClass(),
                'resource_id' => $item?->getKey(),
                'exception' => $exception ? (string) $exception : null,
                'metadata' => $metadata ?: null,
                'failed_at' => $this->freshTimestamp(),
            ]);

            if ($count) {
                $this->incrementFailed();
            }

            return $record;
        });
    }

    /**
     * Increment the failed item count.
     */
    public function incrementFailed(int $amount = 1): int
    {
        if (! $this->exists) {
            throw new InvalidArgumentException('The run must be saved before incrementing its failure count.');
        }

        $updated = $this->newQuery()
            ->whereKey($this)
            ->toBase()
            ->incrementEach([
                'processed_items' => $amount,
                'failed_items' => $amount,
            ], [
                $this->getUpdatedAtColumn() => $this->freshTimestampString(),
            ]);

        if ($updated) {
            $this->refresh();
        }

        return $updated;
    }

    /**
     * Lock the run before a ledger insert takes a shared foreign key lock.
     */
    protected function lockForCounterUpdate(): void
    {
        $this->newModelQuery()
            ->whereKey($this->getKey())
            ->lockForUpdate()
            ->toBase()
            ->first([$this->getKeyName()]);
    }
}
