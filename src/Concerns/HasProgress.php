<?php

namespace DirectoryTree\Progress\Concerns;

use DirectoryTree\Progress\Models\Progress;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasProgress
{
    /**
     * The progress for this model.
     */
    public function progress(): MorphMany
    {
        return $this->morphMany(Progress::class, 'subject');
    }
}
