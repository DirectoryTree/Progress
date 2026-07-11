<?php

namespace DirectoryTree\Stride\Concerns;

use DirectoryTree\Stride\Models\Run;
use Illuminate\Container\Container;

trait InteractsWithRun
{
    /**
     * The run ID.
     */
    public ?int $runId = null;

    /**
     * Get the run instance for the job, if applicable.
     */
    public function run(): ?Run
    {
        if (! $this->runId) {
            return null;
        }

        return Container::getInstance()
            ->make(Run::class)
            ->newQuery()
            ->find($this->runId);
    }

    /**
     * Determine if the job belongs to an active run.
     */
    public function runActive(): bool
    {
        return $this->run()?->isActive() ?? false;
    }

    /**
     * Set the run ID on the job.
     *
     * @return $this
     */
    public function withRun(Run $run): static
    {
        $this->runId = $run->getKey();

        return $this;
    }
}
