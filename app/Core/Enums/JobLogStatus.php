<?php

namespace App\Core\Enums;

enum JobLogStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
}
