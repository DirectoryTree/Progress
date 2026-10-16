<?php

namespace DirectoryTree\Progress\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \DirectoryTree\Progress\Models\Progress
 *
 * @extends Builder<TModel>
 */
class ProgressBuilder extends Builder
{
    /**
     * Limit the query to active progress.
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
     * Limit the query to completed progress.
     */
    public function completed(): static
    {
        return $this->whereNotNull('completed_at');
    }

    /**
     * Limit the query to failed progress.
     */
    public function failed(): static
    {
        return $this->whereNotNull('failed_at');
    }

    /**
     * Limit the query to cancelled progress.
     */
    public function cancelled(): static
    {
        return $this->whereNotNull('cancelled_at');
    }
}
