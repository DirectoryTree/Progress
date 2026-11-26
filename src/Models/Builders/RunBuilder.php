<?php

namespace DirectoryTree\Progress\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \DirectoryTree\Progress\Models\Run
 *
 * @extends Builder<TModel>
 */
class RunBuilder extends Builder
{
    /**
     * Limit the query to active runs.
     */
    public function active(): static
    {
        return $this->whereNull([
            'failed_at',
            'completed_at',
            'cancelled_at',
        ]);
    }

    /**
     * Limit the query to completed runs.
     */
    public function completed(): static
    {
        return $this->whereNotNull('completed_at');
    }

    /**
     * Limit the query to failed runs.
     */
    public function failed(): static
    {
        return $this->whereNotNull('failed_at');
    }

    /**
     * Limit the query to cancelled runs.
     */
    public function cancelled(): static
    {
        return $this->whereNotNull('cancelled_at');
    }
}
