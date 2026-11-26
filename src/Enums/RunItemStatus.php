<?php

namespace DirectoryTree\Progress\Enums;

enum RunItemStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
}
