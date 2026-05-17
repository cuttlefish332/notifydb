<?php

namespace App\Message;

final readonly class SendInstantChangeEventEmailMessage
{
    public function __construct(
        public int $changeEventId,
    ) {
    }
}
