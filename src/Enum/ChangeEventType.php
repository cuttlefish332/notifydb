<?php

namespace App\Enum;

enum ChangeEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
