<?php

namespace DirectoryTree\Stride\Models;

use DirectoryTree\Stride\Enums\RunStatus;
use DirectoryTree\Stride\Models\Builders\RunBuilder;
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
     * Create and start a run.
     */
    public static function start(
        ?string $name = null,
        ?string $type = null,
        ?int $total = null,
        ?Model $subject = null,
        ?Model $owner = null,
        array $metadata = [],
    ): static {
        $run = new static;

        $run->fill([
            'name' => $name,
            'type' => $type,
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
     * The model or process this run tracks.
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
        return $this->hasMany(RunItem::class);
    }

    /**
     * Determine if the run is active.
     */
    public function isActive(): bool
    {
        return in_array($this->status, [RunStatus::Pending, RunStatus::Running], true);
    }

    /**
     * Determine if the run has finished.
     */
    public function finished(): bool
    {
        return ! is_null($this->completed_at) || ! is_null($this->failed_at) || ! is_null($this->cancelled_at);
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
    public function progress(): ?int
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
     * Increment the processed item count.
     */
    public function incrementProcessed(int $amount = 1): int
    {
        return $this->incrementCounters([
            'processed_items' => $amount,
        ]);
    }

    /**
     * Increment the failed item count.
     */
    public function incrementFailed(int $amount = 1): int
    {
        return $this->incrementCounters([
            'processed_items' => $amount,
            'failed_items' => $amount,
        ]);
    }

    /**
     * Increment the given run counters.
     *
     * @param  array<string, int>  $counters
     */
    protected function incrementCounters(array $counters): int
    {
        $updatedAt = $this->freshTimestamp();

        $updated = $this->newQuery()
            ->whereKey($this)
            ->toBase()
            ->incrementEach($counters, [
                $this->getUpdatedAtColumn() => $this->fromDateTime($updatedAt),
            ]);

        if ($updated) {
            foreach ($counters as $column => $amount) {
                $this->{$column} += $amount;
            }

            $this->updated_at = $updatedAt;
            $this->syncOriginalAttributes([...array_keys($counters), $this->getUpdatedAtColumn()]);
        }

        return $updated;
    }

    /**
     * Record a processed item for the run.
     */
    public function record(?Model $item = null, array $metadata = [], bool $count = true): ?RunItem
    {
        if (! $item) {
            if ($count) {
                $this->incrementProcessed();
            }

            return null;
        }

        if (! $item->exists) {
            throw new InvalidArgumentException('Run items must be persisted Eloquent models. Use missed() to record failures without a model.');
        }

        return $this->getConnection()->transaction(function () use ($item, $metadata, $count) {
            /** @var RunItem $record */
            $record = $this->items()->create([
                'metadata' => $metadata ?: null,
                'completed_at' => $this->freshTimestamp(),
                'resource_type' => $item->getMorphClass(),
                'resource_id' => $item->getKey(),
            ]);

            if ($count) {
                $this->incrementProcessed();
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
    ): RunItem {
        if ($item && ! $item->exists) {
            throw new InvalidArgumentException('Run items must be persisted Eloquent models.');
        }

        return $this->getConnection()->transaction(function () use ($exception, $item, $metadata, $count) {
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
}
