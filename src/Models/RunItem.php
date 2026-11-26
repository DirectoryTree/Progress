<?php

namespace DirectoryTree\Progress\Models;

use DirectoryTree\Progress\Enums\RunStatus;
use DirectoryTree\Progress\Models\Builders\RunItemBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RunItem extends Model
{
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
            'metadata' => 'array',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Create a new Eloquent query builder for the model.
     */
    public function newEloquentBuilder($query): RunItemBuilder
    {
        return new RunItemBuilder($query);
    }

    /**
     * The run this item belongs to.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * The tracked model.
     */
    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the status derived from the lifecycle timestamps.
     */
    protected function status(): Attribute
    {
        return Attribute::get(fn () => match (true) {
            ! is_null($this->failed_at) => RunStatus::Failed,
            ! is_null($this->completed_at) => RunStatus::Completed,
            default => RunStatus::Pending,
        })->withoutObjectCaching();
    }
}
