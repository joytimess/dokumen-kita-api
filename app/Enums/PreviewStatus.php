<?php

namespace App\Enums;

enum PreviewStatus: string
{
    case None = 'none';
    case Queued = 'queued';
    case Ready = 'ready';
    case Failed = 'failed';
    case Stale = 'stale';
}
