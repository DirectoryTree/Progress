<?php

namespace DirectoryTree\Progress\Contracts;

use DirectoryTree\Progress\Models\Progress;

interface TracksProgress
{
    /**
     * Create the progress owned by the job.
     */
    public function createProgress(): Progress;

    /**
     * Get the progress assigned to the job.
     */
    public function getProgress(): ?Progress;

    /**
     * Set the progress assigned to the job.
     */
    public function setProgress(Progress $progress): static;
}
