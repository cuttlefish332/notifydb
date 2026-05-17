<?php

namespace App\Service;

use App\Entity\ChangeEvent;

interface AiSummaryServiceInterface
{
    public function summarize(ChangeEvent $event): string;
}
