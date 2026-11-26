<?php

namespace DirectoryTree\Progress\Concerns;

use DirectoryTree\Progress\Models\Run;

trait InteractsWithRun
{
    /**
     * The run owned by the job.
     */
    public ?Run $run = null;

    /**
     * Create the run owned by the job.
     */
    public function createRun(): Run
    {
        return Run::create([
            'name' => class_basename(static::class),
        ]);
    }

    /**
     * Get the run assigned to the job.
     */
    public function getRun(): ?Run
    {
        return $this->run;
    }

    /**
     * Set the run assigned to the job.
     */
    public function setRun(Run $run): static
    {
        $this->run = $run;

        return $this;
    }
}
