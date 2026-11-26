<?php

namespace DirectoryTree\Progress\Models;

use DirectoryTree\Progress\Enums\RunItemStatus;
use DirectoryTree\Progress\Models\Builders\RunItemBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RunItem extends Model
{
    public const UPDATED_AT = null;

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
            'status' => RunItemStatus::class,
            'metadata' => 'array',
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
}
