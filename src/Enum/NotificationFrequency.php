<?php

namespace App\Enum;

enum NotificationFrequency: string
{
    case Instant = 'instant';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Off = 'off';
}
