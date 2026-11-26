<?php

namespace DirectoryTree\Progress\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Determine if the status represents an active run.
     */
    public function active(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}
