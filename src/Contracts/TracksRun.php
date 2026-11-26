<?php

namespace DirectoryTree\Progress\Contracts;

use DirectoryTree\Progress\Models\Run;

interface TracksRun
{
    /**
     * Create the run owned by the job.
     */
    public function createRun(): Run;

    /**
     * Get the run assigned to the job.
     */
    public function getRun(): ?Run;

    /**
     * Set the run assigned to the job.
     */
    public function setRun(Run $run): static;
}
