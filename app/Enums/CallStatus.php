<?php

declare(strict_types=1);

namespace App\Enums;

enum CallStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case Failed = 'failed';
}
