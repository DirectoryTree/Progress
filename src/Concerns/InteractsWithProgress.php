<?php

namespace DirectoryTree\Progress\Concerns;

use DirectoryTree\Progress\Models\Progress;

trait InteractsWithProgress
{
    /**
     * The progress owned by the job.
     */
    public ?Progress $progress = null;

    /**
     * Create the progress owned by the job.
     */
    public function createProgress(): Progress
    {
        return Progress::create([
            'name' => class_basename(static::class),
        ]);
    }

    /**
     * Get the progress assigned to the job.
     */
    public function getProgress(): ?Progress
    {
        return $this->progress;
    }

    /**
     * Set the progress assigned to the job.
     */
    public function setProgress(Progress $progress): static
    {
        $this->progress = $progress;

        return $this;
    }
}
