<?php

namespace DirectoryTree\Progress\Models\Builders;

use DirectoryTree\Progress\Enums\RunItemStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \DirectoryTree\Progress\Models\RunItem
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
        return $this->where('status', RunItemStatus::Completed);
    }

    /**
     * Limit the query to failed items.
     */
    public function failed(): static
    {
        return $this->where('status', RunItemStatus::Failed);
    }
}
