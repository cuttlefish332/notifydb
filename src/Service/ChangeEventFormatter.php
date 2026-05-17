<?php

namespace App\Service;

use App\Entity\ChangeEvent;

final class ChangeEventFormatter
{
    public function format(ChangeEvent $event): string
    {
        return $event->getHumanSummary() ?? $this->formatRaw($event);
    }

    public function formatRaw(ChangeEvent $event): string
    {
        return sprintf(
            "%s %s record %s\nOld values: %s\nNew values: %s",
            ucfirst($event->getEventType()->value),
            $event->getTableName(),
            $event->getRecordId(),
            json_encode($event->getOldValues(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            json_encode($event->getNewValues(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
