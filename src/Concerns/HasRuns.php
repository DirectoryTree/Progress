<?php

namespace DirectoryTree\Stride\Concerns;

use DirectoryTree\Stride\Models\Run;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasRuns
{
    /**
     * The runs for this model.
     */
    public function runs(): MorphMany
    {
        return $this->morphMany(Run::class, 'subject');
    }
}
