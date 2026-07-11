<?php

namespace DirectoryTree\Stride\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \DirectoryTree\Stride\Models\RunItem
 *
 * @extends Builder<TModel>
 */
class RunItemBuilder extends Builder
{
    /**
     * Limit the query to completed items.
     */
    public function completed(): static
    {
        return $this->whereNotNull('completed_at');
    }

    /**
     * Limit the query to failed items.
     */
    public function failed(): static
    {
        return $this->whereNotNull('failed_at');
    }
}
