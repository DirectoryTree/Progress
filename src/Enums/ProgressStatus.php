<?php

namespace DirectoryTree\Progress\Enums;

enum ProgressStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Determine if the status represents an active progress.
     */
    public function active(): bool
    {
        return in_array($this, [self::Pending, self::Running], true);
    }
}
